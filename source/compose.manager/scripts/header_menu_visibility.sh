#!/bin/bash

cfg_file='/boot/config/plugins/compose.manager/compose.manager.cfg'
mode="${1:-show}"

show_in_header=''
if [[ -f "$cfg_file" ]]; then
    show_in_header=$(awk -F= '/^SHOW_COMPOSE_IN_HEADER_MENU=/{
        gsub(/"/, "", $2)
        gsub(/[[:space:]]/, "", $2)
        print tolower($2)
        exit
    }' "$cfg_file")
fi

if [[ "$mode" == "show" ]]; then
    [[ "$show_in_header" == "true" ]] && echo 1 || echo 0
else
    [[ "$show_in_header" == "true" ]] && echo 0 || echo 1
fi