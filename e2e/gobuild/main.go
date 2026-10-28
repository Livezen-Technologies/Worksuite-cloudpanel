// Tiny web app built from a local Git repository by the e2e build suite (e2e/build.sh).
// mode is set at link time: "good" serves 200 on /healthz, "broken" logs 60 lines and answers 500.
package main

import (
	"fmt"
	"net/http"
	"os"
)

var mode = "good"

func main() {
	port := os.Getenv("PORT")
	if port == "" {
		port = "8080"
	}
	if mode == "broken" {
		for i := 1; i <= 60; i++ {
			fmt.Printf("broken: line %d\n", i)
		}
	}
	fmt.Println("listening on :" + port)
	http.HandleFunc("/healthz", func(w http.ResponseWriter, r *http.Request) {
		if mode == "broken" {
			http.Error(w, "database unreachable", http.StatusInternalServerError)
			return
		}
		fmt.Fprintln(w, "ok")
	})
	http.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) { fmt.Fprintln(w, "built by e2e ("+mode+")") })
	_ = http.ListenAndServe(":"+port, nil)
}
