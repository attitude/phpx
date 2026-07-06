# Editor setup

PHPX ships a language server that speaks the standard [Language Server Protocol](https://microsoft.github.io/language-server-protocol/) (JSON-RPC 2.0) over stdin/stdout. It is editor-agnostic — any LSP-capable editor can use it, not just VS Code.

It currently provides:

- **Diagnostics** — parse errors, and HTML-vs-JSX attribute hints (e.g. `class` → `className`)
- **Completion** — tags, attributes, closing tags (trigger characters `<`, `/`, space)
- **Hover** — tag and attribute documentation
- **Rename** — with prepare support

## The server command

```shell
php /path/to/phpx/scripts/language-server.php
```

- Communicates over **stdio** (stdin/stdout). Do not print to stdout from anything else.
- Pass `--debug` to log to **stderr**.
- Files use language id **`phpx`** and the **`.phpx`** extension.

When PHPX is installed via Composer, the script lives at `vendor/attitude/phpx/scripts/language-server.php`.

### Verify it works

You can drive the server by hand — send a framed `initialize` request and read the framed response:

```shell
php -r '
  $repo = getcwd();
  $body = json_encode(["jsonrpc"=>"2.0","id"=>1,"method"=>"initialize","params"=>["capabilities"=>[]]]);
  $msg = "Content-Length: ".strlen($body)."\r\n\r\n".$body;
  $p = proc_open([PHP_BINARY, "scripts/language-server.php"], [0=>["pipe","r"],1=>["pipe","w"],2=>["pipe","w"]], $pipes, $repo);
  fwrite($pipes[0], $msg); fclose($pipes[0]);
  echo stream_get_contents($pipes[1]); proc_close($p);
'
```

You should see a `Content-Length` header followed by a JSON response containing `"serverInfo":{"name":"phpx-language-server",...}`.

## Neovim (0.11+)

Neovim's built-in LSP client can start the server directly — no plugin required.

```lua
-- Treat .phpx files as their own filetype
vim.filetype.add({ extension = { phpx = "phpx" } })

vim.lsp.config("phpx", {
  cmd = { "php", vim.fn.expand("~/path/to/phpx/scripts/language-server.php") },
  filetypes = { "phpx" },
  root_markers = { "composer.json", ".git" },
})

vim.lsp.enable("phpx")
```

On older Neovim, use [nvim-lspconfig](https://github.com/neovim/nvim-lspconfig)'s manual `configs` API with the same `cmd`/`filetypes`.

## Helix

Add to `~/.config/helix/languages.toml`:

```toml
[language-server.phpx]
command = "php"
args = ["/path/to/phpx/scripts/language-server.php"]

[[language]]
name = "phpx"
scope = "source.phpx"
file-types = ["phpx"]
roots = ["composer.json", ".git"]
language-servers = ["phpx"]
```

## Sublime Text (LSP package)

With the [LSP](https://packagecontrol.io/packages/LSP) package installed, add to its settings:

```json
{
  "clients": {
    "phpx": {
      "enabled": true,
      "command": ["php", "/path/to/phpx/scripts/language-server.php"],
      "selector": "source.phpx",
      "languageId": "phpx"
    }
  }
}
```

## JetBrains IDEs (PhpStorm, IntelliJ)

Install the [LSP4IJ](https://plugins.jetbrains.com/plugin/23257-lsp4ij) plugin, then add a **User-Defined Language Server**:

- **Command:** `php /path/to/phpx/scripts/language-server.php`
- **File name patterns:** `*.phpx`, language id `phpx`

## Zed

Zed exposes custom language servers through a small [Zed extension](https://zed.dev/docs/extensions/languages). The extension registers a `phpx` language for `.phpx` files and returns the command `php scripts/language-server.php` from its `language_server_command`. Point it at your PHPX install to get diagnostics, completion, and hover.

---

The VS Code extension under [`extension/`](../extension) adds editor-specific niceties on top of this server; every other editor gets the same core intelligence straight from the LSP.
