package executor

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
)

// cacheStats counts classic-builder steps and layer cache hits (BLD-3).
type cacheStats struct{ steps, hits int }

func (c *cacheStats) line(l string) {
	switch t := strings.TrimSpace(l); {
	case strings.HasPrefix(t, "Step ") && strings.Contains(t, " : "):
		c.steps++
	case t == "---> Using cache":
		c.hits++
	}
}

func exists(dir, name string) bool {
	st, err := os.Lstat(filepath.Join(dir, name))
	return err == nil && st.Mode().IsRegular()
}

// detectStack picks a stack from marker files and returns a Dockerfile for
// it, in the spirit of Nixpacks/Railpack. The generated images listen on
// $PORT (default 8080).
func detectStack(dir string) (string, string) {
	switch {
	case exists(dir, "package.json"):
		var pkg struct {
			Scripts map[string]string `json:"scripts"`
		}
		if b, err := os.ReadFile(filepath.Join(dir, "package.json")); err == nil {
			_ = json.Unmarshal(b, &pkg)
		}
		install := "npm install"
		switch {
		case exists(dir, "package-lock.json"):
			install = "npm ci"
		case exists(dir, "yarn.lock"):
			install = "corepack enable && yarn install --frozen-lockfile"
		case exists(dir, "pnpm-lock.yaml"):
			install = "corepack enable && pnpm install --frozen-lockfile"
		}
		build := ""
		if _, ok := pkg.Scripts["build"]; ok {
			build = "RUN npm run build\n"
		}
		return "node", "FROM node:22-alpine\nWORKDIR /app\nENV PORT=8080 NODE_ENV=production\nCOPY package*.json yarn.lock* pnpm-lock.yaml* ./\nRUN " + install + "\nCOPY . .\n" + build + "EXPOSE 8080\nCMD [\"npm\", \"start\"]\n"
	case exists(dir, "requirements.txt"), exists(dir, "pyproject.toml"):
		install := "pip install --no-cache-dir -r requirements.txt"
		if !exists(dir, "requirements.txt") {
			install = "pip install --no-cache-dir ."
		}
		return "python", "FROM python:3.12-slim\nWORKDIR /app\nENV PORT=8080 PYTHONUNBUFFERED=1\nCOPY . .\nRUN " + install + " && pip install --no-cache-dir gunicorn\nEXPOSE 8080\nCMD [\"sh\", \"-c\", \"gunicorn --bind 0.0.0.0:${PORT} ${APP_MODULE:-app:app}\"]\n"
	case exists(dir, "go.mod"):
		return "go", "FROM golang:1.24-alpine AS build\nWORKDIR /src\nCOPY go.* ./\nRUN go mod download\nCOPY . .\nRUN CGO_ENABLED=0 go build -o /out/app .\nFROM alpine:3.20\nENV PORT=8080\nCOPY --from=build /out/app /app\nEXPOSE 8080\nCMD [\"/app\"]\n"
	case exists(dir, "composer.json"):
		return "php", "FROM composer:2 AS vendor\nWORKDIR /app\nCOPY . .\nRUN composer install --no-dev --optimize-autoloader --no-interaction\nFROM php:8.3-apache\nCOPY --from=vendor /app /var/www/html\nRUN sed -i 's/80/8080/g' /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf\nEXPOSE 8080\n"
	case exists(dir, "index.html"):
		return "static", "FROM nginx:1.27-alpine\nCOPY . /usr/share/nginx/html\nRUN sed -i 's/listen  *80;/listen 8080;/' /etc/nginx/conf.d/default.conf\nEXPOSE 8080\n"
	}
	return "", ""
}
