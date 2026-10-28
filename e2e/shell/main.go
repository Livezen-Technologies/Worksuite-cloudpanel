// Command sh is a tiny static line shell for the terminal e2e image (FROM scratch, no busybox):
// a prompt, echo, id, whoami, pwd, env, sleep, exit. `sh --serve` keeps the container running.
package main

import (
	"bufio"
	"fmt"
	"os"
	"os/signal"
	"os/user"
	"strconv"
	"strings"
	"syscall"
	"time"
)

func main() {
	if len(os.Args) > 1 && os.Args[1] == "--serve" {
		c := make(chan os.Signal, 1)
		signal.Notify(c, syscall.SIGTERM, syscall.SIGINT)
		<-c
		return
	}
	in := bufio.NewScanner(os.Stdin)
	prompt := func() { fmt.Print("$ ") }
	prompt()
	for in.Scan() {
		f := strings.Fields(strings.TrimSpace(in.Text()))
		if len(f) == 0 {
			prompt()
			continue
		}
		switch f[0] {
		case "exit":
			return
		case "echo":
			fmt.Println(strings.Join(f[1:], " "))
		case "id":
			uid, gid := os.Getuid(), os.Getgid()
			name := strconv.Itoa(uid)
			if u, err := user.LookupId(name); err == nil {
				name = u.Username
			}
			fmt.Printf("uid=%d(%s) gid=%d\n", uid, name, gid)
		case "whoami":
			if u, err := user.Current(); err == nil {
				fmt.Println(u.Username)
			} else {
				fmt.Println(os.Getuid())
			}
		case "pwd":
			d, _ := os.Getwd()
			fmt.Println(d)
		case "env":
			for _, e := range os.Environ() {
				fmt.Println(e)
			}
		case "sleep":
			if len(f) > 1 {
				n, _ := strconv.Atoi(f[1])
				time.Sleep(time.Duration(n) * time.Second)
			}
		default:
			fmt.Printf("sh: %s: not found\n", f[0])
		}
		prompt()
	}
}
