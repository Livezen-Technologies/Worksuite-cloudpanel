//go:build linux

package executor

import (
	"errors"
	"os"
	"syscall"
)

func renameAt(dir *os.File, oldName, newName string) error {
	return renameBetween(dir, oldName, dir, newName)
}

// renameBetween moves one entry between two already-opened folders. Both
// names are single components, so nothing is resolved outside those folders.
func renameBetween(from *os.File, oldName string, to *os.File, newName string) error {
	if err := syscall.Renameat(int(from.Fd()), oldName, int(to.Fd()), newName); err != nil {
		return &os.LinkError{Op: "rename", Old: oldName, New: newName, Err: err}
	}
	return nil
}

func isCrossDevice(err error) bool { return errors.Is(err, syscall.EXDEV) }

// inheritOwner gives a new file or folder the owner of its parent, so the
// container's own user can still write to what the panel created.
func inheritOwner(root *os.Root, parent string, f *os.File) {
	fi, err := root.Stat(parent)
	if err != nil {
		return
	}
	if st, ok := fi.Sys().(*syscall.Stat_t); ok {
		_ = f.Chown(int(st.Uid), int(st.Gid))
	}
}

func ownerOf(fi os.FileInfo) (int, int) {
	if st, ok := fi.Sys().(*syscall.Stat_t); ok {
		return int(st.Uid), int(st.Gid)
	}
	return -1, -1
}
