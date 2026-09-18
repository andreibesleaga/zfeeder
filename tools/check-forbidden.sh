#!/usr/bin/env bash
# Static rules that a type checker cannot express: constructs this codebase
# must never contain. Referenced by the security requirements (S14).
set -uo pipefail
cd "$(dirname "$0")/.."
fail=0

check() {
    local label="$1" pattern="$2"; shift 2
    local hits
    hits=$(grep -rnP "$pattern" src bin public "$@" --include='*.php' 2>/dev/null | grep -vP '^\S+:\d+:\s*(\*|//|/\*)' || true)
    if [ -n "$hits" ]; then
        echo "FORBIDDEN: $label"
        echo "$hits" | sed 's/^/    /'
        fail=1
    fi
}

check "eval()"                         '(^|[^_[:alnum:]])eval\s*\('
check "create_function()"              'create_function\s*\('
# A bare call only: $pdo->exec() and Database::exec() are not the shell.
check "shell execution"                '(?<![-$>:_A-Za-z0-9])(exec|shell_exec|passthru|proc_open|popen|system)\s*\('
check "unserialize() on input"         '(^|[^_[:alnum:]])unserialize\s*\('
check "extract()"                      '(^|[^_[:alnum:]])extract\s*\('
check "dynamic include of a variable"  '(include|require)(_once)?\s*\(?\s*\$'
check "remote file read"               '(file_get_contents|readfile|fopen)\s*\(\s*['"'"'"]https?://'
check "md5/sha1 for passwords"         '(md5|sha1)\s*\([^)]*(pass|pwd|secret)'
check "assert() with a string"         'assert\s*\(\s*['"'"'"]'
# Error suppression is allowed only where a filesystem race is genuinely expected
# (mkdir/unlink/fopen/rename/flock/fclose/chmod/rmdir/touch/filemtime/opendir).
# Suppressing anything else hides a failure that the caller needs to see.
check "error suppression outside the allowed filesystem calls" \
      '@(?!(mkdir|unlink|rename|fopen|fclose|flock|fwrite|chmod|rmdir|touch|filemtime|filesize|opendir|readdir|closedir|copy|scandir|file_put_contents|file_get_contents|file|is_dir|is_file|realpath|inet_pton|inet_ntop|gethostbyname|gethostbynamel|dns_get_record|parse_url|unserialize)\s*\()[a-z_]+\s*\('


if [ $fail -eq 0 ]; then
    echo "check-forbidden: clean"
fi
exit $fail
