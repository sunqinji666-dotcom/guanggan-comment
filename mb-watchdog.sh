#!/bin/bash
# Musicbed 服务看门狗：检查 mb-feishu 心跳，卡死（>4 分钟无心跳）则自动重启
HB=/opt/mb-agent/heartbeat
LOG=/opt/mb-agent/watchdog.log
if [ -f "$HB" ]; then
  NOW=$(date +%s%3N)
  LAST=$(cat "$HB")
  AGE=$(( (NOW - LAST) / 1000 ))
  if [ "$AGE" -gt 240 ]; then
    echo "[$(date '+%m-%d %H:%M:%S')] mb-feishu 心跳 ${AGE}s 无更新，重启" >> "$LOG"
    systemctl restart mb-feishu
  fi
else
  echo "[$(date '+%m-%d %H:%M:%S')] mb-feishu 无心跳文件，重启" >> "$LOG"
  systemctl restart mb-feishu
fi
