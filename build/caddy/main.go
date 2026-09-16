// Build the standard Caddy distribution with the reviewed module lock.
package main

import (
    caddycmd "github.com/caddyserver/caddy/v2/cmd"
    _ "github.com/caddyserver/caddy/v2/modules/standard"
)

func main() { caddycmd.Main() }
