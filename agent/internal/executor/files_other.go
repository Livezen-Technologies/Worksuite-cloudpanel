//go:build !linux

package executor

import (
	"errors"
	"os"
)

func renameAt(*os.File, string, string) error {
	return errors.New("rename is only supported on Linux")
}

func inheritOwner(*os.Root, string, *os.File) {}
