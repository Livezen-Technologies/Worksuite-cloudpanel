//go:build !linux

package executor

import (
	"errors"
	"os"
)

func renameAt(*os.File, string, string) error {
	return errors.New("rename is only supported on Linux")
}

func renameBetween(*os.File, string, *os.File, string) error {
	return errors.New("rename is only supported on Linux")
}

func isCrossDevice(error) bool { return false }

func inheritOwner(*os.Root, string, *os.File) {}

func ownerOf(os.FileInfo) (int, int) { return -1, -1 }

func chmodAt(*os.File, string, os.FileMode) error {
	return errors.New("chmod is only supported on Linux")
}
