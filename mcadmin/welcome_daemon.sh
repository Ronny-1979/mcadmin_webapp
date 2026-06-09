#!/bin/bash
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

SERVICE_NAME="minecraft-bedrock"
FIFO="/opt/minecraft-bedrock/server.stdin"
STATE_FILE="/var/www/html/mcadmin/mcadmin_state.json"
WORLDS_DIR="/opt/minecraft-bedrock/worlds"
PID_FILE="/tmp/mcadmin_welcome.pid"

echo $$ > "$PID_FILE"
trap 'rm -f "$PID_FILE"' EXIT

while IFS= read -r line; do
    if [[ "$line" =~ Player\ connected:\ ([^,]+), ]]; then
        PLAYER="${BASH_REMATCH[1]}"
        WORLD=$(jq -r '.active_world // empty' "$STATE_FILE" 2>/dev/null)
        [ -z "$WORLD" ] && continue
        WELCOME_FILE="$WORLDS_DIR/$WORLD/.mcadmin_welcome.txt"
        [ -f "$WELCOME_FILE" ] || continue
        sleep 2
        SERVER_NAME=$(grep -m1 '^server-name=' "/opt/minecraft-bedrock/server.properties" 2>/dev/null | cut -d'=' -f2-)
        DISPLAY_MODE="say"
        DISPLAY_DURATION=120
        CONFIG=$(head -1 "$WELCOME_FILE")
        if [[ "$CONFIG" =~ ^#display:([a-z]+):([0-9]+)$ ]]; then
            DISPLAY_MODE="${BASH_REMATCH[1]}"
            DISPLAY_DURATION="${BASH_REMATCH[2]}"
        fi
        if [ "$DISPLAY_MODE" = "title" ] && [ -p "$FIFO" ]; then
            printf 'title @a[name=%s] times 10 %d 10\n' "$PLAYER" "$DISPLAY_DURATION" > "$FIFO"
            sleep 0.1
        fi
        MSG_LINE_NUM=0
        while IFS= read -r msg_line || [ -n "$msg_line" ]; do
            msg_line="${msg_line#"${msg_line%%[![:space:]]*}"}"
            msg_line="${msg_line%"${msg_line##*[![:space:]]}"}"
            [ -z "$msg_line" ] && continue
            [[ "$msg_line" =~ ^#display: ]] && continue
            msg_line="${msg_line//\{player\}/$PLAYER}"
            msg_line="${msg_line//\{world\}/$WORLD}"
            msg_line="${msg_line//\{server\}/$SERVER_NAME}"
            MSG_LINE_NUM=$((MSG_LINE_NUM + 1))
            if [ "$DISPLAY_MODE" = "title" ] && [ -p "$FIFO" ]; then
                if [ $MSG_LINE_NUM -eq 1 ]; then
                    printf 'title @a[name=%s] title %s\n' "$PLAYER" "$msg_line" > "$FIFO"
                elif [ $MSG_LINE_NUM -eq 2 ]; then
                    printf 'title @a[name=%s] subtitle %s\n' "$PLAYER" "$msg_line" > "$FIFO"
                else
                    printf 'say %s\n' "$msg_line" > "$FIFO"
                fi
            else
                printf 'say %s\n' "$msg_line" > "$FIFO"
            fi
            sleep 0.2
        done < "$WELCOME_FILE"
    fi
done < <(journalctl -fu "$SERVICE_NAME" --output=cat -n 0 2>/dev/null)
