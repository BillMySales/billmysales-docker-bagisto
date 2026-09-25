#!/bin/sh
# Sourced by run.sh and setup.sh: Laravel's MAIL_* settings from SMTP_*.
# Bagisto's mailer (bagisto-dynamic-smtp) uses the SMTP settings saved in the
# admin (Configuration > Emails) first and these as the fallback; without
# SMTP_HOST, Laravel's `log` mailer discards them (it logs them at debug
# level; the dynamic mailer would fail every send).
# shellcheck disable=SC2034 # exported for Laravel
if [ -n "${SMTP_HOST:-}" ]; then
    MAIL_MAILER=bagisto-dynamic-smtp
else
    MAIL_MAILER=log
fi
MAIL_HOST="${SMTP_HOST:-}"
MAIL_PORT="${SMTP_PORT:-587}"
# ssl = SMTPS; anything else: STARTTLS when the server offers it.
MAIL_ENCRYPTION="${SMTP_SECURE:-tls}"
MAIL_USERNAME="${SMTP_USER:-}"
MAIL_PASSWORD="${SMTP_PASSWORD:-}"
export MAIL_MAILER MAIL_HOST MAIL_PORT MAIL_ENCRYPTION MAIL_USERNAME MAIL_PASSWORD
