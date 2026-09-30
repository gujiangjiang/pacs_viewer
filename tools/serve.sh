#!/usr/bin/env bash
# ============================================================
# tools/serve.sh — 本地开发服务器守护（检查 / 启动 / 重启）
# ============================================================
# 背景：本机无系统 php，使用 ~/.local/bin/frankenphp 起 PHP 开发服务器。
# 该进程常因终端会话结束、端口冲突或误杀而断开；本脚本提供统一的
# 「健康检查 + 挂了就重启」能力，供人工与 AI 协作者在每次任务收尾前调用。
#
# 用法：
#   tools/serve.sh ensure   # 挂了就重启（默认动作，最常用）
#   tools/serve.sh status   # 仅打印 up / down
#   tools/serve.sh start    # 强制启动（已在运行则跳过）
#   tools/serve.sh restart  # 强制重启
#   tools/serve.sh stop     # 停止
#
# 可用环境变量覆盖：PV_HOST（默认 0.0.0.0）、PV_PORT（默认 8090）、
#                    FRANKENPHP（默认 ~/.local/bin/frankenphp）
# ============================================================
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
HOST="${PV_HOST:-0.0.0.0}"
PORT="${PV_PORT:-8090}"
BIN="${FRANKENPHP:-$HOME/.local/bin/frankenphp}"
PIDFILE="$ROOT/data/.serve.pid"
LOG="$ROOT/data/.serve.log"
URL="http://127.0.0.1:${PORT}/?r=manifest"

mkdir -p "$ROOT/data"

is_up() {
    curl -fsS -m 3 -o /dev/null "$URL" >/dev/null 2>&1
}

do_start() {
    if is_up; then echo "up (:$PORT) — 无需启动"; return 0; fi
    if [ ! -x "$BIN" ]; then echo "ERROR: 未找到 frankenphp：$BIN" >&2; return 1; fi
    ( cd "$ROOT" && nohup "$BIN" php-server --root public --listen "$HOST:$PORT" >>"$LOG" 2>&1 & echo $! >"$PIDFILE" )
    for _ in 1 2 3 4 5 6 7 8 9 10; do
        sleep 1
        if is_up; then echo "started (:$PORT, pid $(cat "$PIDFILE" 2>/dev/null))"; return 0; fi
    done
    echo "ERROR: 启动后仍不可用，请查看 $LOG" >&2
    return 1
}

do_stop() {
    if [ -f "$PIDFILE" ]; then
        local pid; pid="$(cat "$PIDFILE" 2>/dev/null || true)"
        if [ -n "${pid:-}" ] && kill -0 "$pid" 2>/dev/null; then kill "$pid" 2>/dev/null || true; fi
        rm -f "$PIDFILE"
    fi
    # 兜底：按端口清理残留监听进程
    local pids; pids="$(lsof -nP -tiTCP:"$PORT" -sTCP:LISTEN 2>/dev/null || true)"
    if [ -n "${pids:-}" ]; then kill $pids 2>/dev/null || true; fi
    echo "stopped (:$PORT)"
}

case "${1:-ensure}" in
    status)  if is_up; then echo "up"; else echo "down"; exit 1; fi ;;
    start)   do_start ;;
    stop)    do_stop ;;
    restart) do_stop; sleep 1; do_start ;;
    ensure)  if is_up; then echo "up (:$PORT)"; else echo "down — 正在重启…"; do_start; fi ;;
    *) echo "用法: $0 {ensure|status|start|stop|restart}" >&2; exit 2 ;;
esac
