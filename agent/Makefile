VERSION ?= dev
UPDATE_PUBLIC_KEY ?=
LDFLAGS := -s -w -X main.version=$(VERSION) -X main.updatePublicKey=$(UPDATE_PUBLIC_KEY)

.PHONY: all test build clean
all: test build

test:
	go vet ./... && go test ./...

build:
	CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build -trimpath -ldflags "$(LDFLAGS)" -o dist/dockermgr-agent-linux-amd64 ./cmd/agent
	CGO_ENABLED=0 GOOS=linux GOARCH=arm64 go build -trimpath -ldflags "$(LDFLAGS)" -o dist/dockermgr-agent-linux-arm64 ./cmd/agent
	CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build -trimpath -ldflags "-s -w -X main.version=$(VERSION)" -o dist/dockermgr-relay-linux-amd64 ./cmd/relay

clean:
	rm -rf dist
