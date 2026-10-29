//go:build linux

package executor

import (
	"os"
	"syscall"
)

func renameAt(dir *os.File, oldName, newName string) error {
	fd := int(dir.Fd())
	if err := syscall.Renameat(fd, oldName, fd, newName); err != nil {
		return &os.LinkError{Op: "rename", Old: oldName, New: newName, Err: err}
	}
	return nil
}

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
