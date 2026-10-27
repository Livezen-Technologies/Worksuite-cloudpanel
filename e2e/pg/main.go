// Command pg-entry is the entrypoint of the locally built e2e/postgres image (no shell, no
// registry pulls): it initialises PGDATA on first start, then execs postgres in the foreground.
package main

import (
	"log"
	"os"
	"os/exec"
	"path/filepath"
	"syscall"
)

const bin = "/usr/lib/postgresql/16/bin"

func main() {
	data := os.Getenv("PGDATA")
	if data == "" {
		data = "/var/lib/postgresql/data"
	}
	if _, err := os.Stat(filepath.Join(data, "PG_VERSION")); err != nil {
		pw := filepath.Join(os.TempDir(), "pw")
		if err := os.WriteFile(pw, []byte(os.Getenv("POSTGRES_PASSWORD")), 0o600); err != nil {
			log.Fatal(err)
		}
		cmd := exec.Command(bin+"/initdb", "-D", data, "-U", "postgres", "--auth-local=trust", "--auth-host=scram-sha-256", "--pwfile="+pw)
		cmd.Stdout, cmd.Stderr = os.Stdout, os.Stderr
		if err := cmd.Run(); err != nil {
			log.Fatal("initdb: ", err)
		}
		os.Remove(pw)
		f, _ := os.OpenFile(filepath.Join(data, "pg_hba.conf"), os.O_APPEND|os.O_WRONLY, 0o600)
		f.WriteString("host all all 0.0.0.0/0 scram-sha-256\n")
		f.Close()
	}
	args := []string{"postgres", "-D", data, "-c", "listen_addresses=*", "-c", "unix_socket_directories=/var/run/postgresql"}
	log.Fatal(syscall.Exec(bin+"/postgres", args, os.Environ()))
}
