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
