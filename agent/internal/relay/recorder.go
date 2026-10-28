package relay

import (
	"bytes"
	"encoding/json"
	"fmt"
	"strings"
	"sync"
	"time"
	"unicode/utf8"
)

// Recorder writes an asciicast v2 recording (JSON lines): a header object,
// then [seconds, "o", data] output events and [seconds, "r", "COLSxROWS"]
// resize events. Input is not recorded (it is echoed by the TTY anyway and
// would otherwise capture typed secrets verbatim).
type Recorder struct {
	mu        sync.Mutex
	buf       bytes.Buffer
	start     time.Time
	pending   []byte // incomplete UTF-8 tail of the last chunk
	Max       int
	truncated bool
	now       func() time.Time
}

// NewRecorder starts a recording with the header written.
func NewRecorder(cols, rows int, title string, start time.Time, max int) *Recorder {
	if cols <= 0 {
		cols = 80
	}
	if rows <= 0 {
		rows = 24
	}
	r := &Recorder{start: start, Max: max, now: time.Now}
	hdr, _ := json.Marshal(map[string]any{
		"version": 2, "width": cols, "height": rows, "timestamp": start.Unix(), "title": title,
		"env": map[string]string{"TERM": "xterm-256color", "SHELL": "/bin/sh"},
	})
	r.buf.Write(hdr)
	r.buf.WriteByte('\n')
	return r
}

func (r *Recorder) event(kind, data string) {
	if r.truncated {
		return
	}
	line, _ := json.Marshal([]any{json.Number(fmt.Sprintf("%.6f", r.now().Sub(r.start).Seconds())), kind, data})
	if r.Max > 0 && r.buf.Len()+len(line)+1 > r.Max {
		r.truncated = true
		note, _ := json.Marshal([]any{json.Number(fmt.Sprintf("%.6f", r.now().Sub(r.start).Seconds())), "o", "\r\n[recording truncated: size limit reached]\r\n"})
		r.buf.Write(note)
		r.buf.WriteByte('\n')
		return
	}
	r.buf.Write(line)
	r.buf.WriteByte('\n')
}

// Output records terminal output, keeping multi-byte runes intact across chunks.
func (r *Recorder) Output(b []byte) {
	r.mu.Lock()
	defer r.mu.Unlock()
	data := append(r.pending, b...)
	cut := len(data)
	// Hold back a trailing partial rune (at most 3 bytes).
	for i := 1; i <= 3 && i <= len(data); i++ {
		c := data[len(data)-i]
		if utf8.RuneStart(c) {
			if !utf8.FullRune(data[len(data)-i:]) {
				cut = len(data) - i
			}
			break
		}
	}
	r.pending = append([]byte(nil), data[cut:]...)
	if cut > 0 {
		r.event("o", strings.ToValidUTF8(string(data[:cut]), "�"))
	}
}

// Resize records a terminal size change.
func (r *Recorder) Resize(cols, rows int) {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.event("r", fmt.Sprintf("%dx%d", cols, rows))
}

// Bytes returns the recording so far.
func (r *Recorder) Bytes() []byte {
	r.mu.Lock()
	defer r.mu.Unlock()
	if len(r.pending) > 0 {
		r.event("o", strings.ToValidUTF8(string(r.pending), "�"))
		r.pending = nil
	}
	return append([]byte(nil), r.buf.Bytes()...)
}
