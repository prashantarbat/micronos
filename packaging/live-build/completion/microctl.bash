# bash completion for the MicronOS Terminal CLI (microctl)
# ==============================================================================
# Staged into the appliance by packaging/live-build/stage-overlay.sh, which
# installs this file as:
#
#   /usr/share/bash-completion/completions/microctl
#
# Debian's `bash-completion` package lazy-loads files from that directory, so the
# completion registers itself for interactive shells without editing any rc file.
# The `complete` call at the bottom is redundant under that framework but keeps
# the file usable when sourced by hand:
#
#   source /usr/share/bash-completion/completions/microctl
#
# Completion is positional because microctl is: argv[0] is the command, argv[1]
# the subcommand, and everything after that belongs to the subcommand.
# ==============================================================================

_microctl() {
    local cur prev
    cur="${COMP_WORDS[COMP_CWORD]}"
    prev="${COMP_WORDS[COMP_CWORD - 1]}"

    local commands="status interface firewall backup config admin help"
    local create_opts="--encrypt --passphrase --description --out"
    local restore_opts="--passphrase --replace-key --nic-map --force"

    # ----------------------------------------------------------------------
    # Values. Secrets and free-form identifiers are never offered back, so a
    # passphrase cannot be leaked into the terminal by a stray TAB.
    # ----------------------------------------------------------------------
    case "${prev}" in
        --passphrase|--description|--nic-map)
            COMPREPLY=()
            return
            ;;
        --out)
            COMPREPLY=($(compgen -d -- "${cur}"))
            return
            ;;
    esac

    # ----------------------------------------------------------------------
    # Options, scoped to the subcommand in play.
    # ----------------------------------------------------------------------
    case "${cur}" in
        -*)
            if [ "${COMP_WORDS[1]}" = "backup" ]; then
                if [ "${COMP_WORDS[2]}" = "restore" ]; then
                    COMPREPLY=($(compgen -W "${restore_opts}" -- "${cur}"))
                else
                    COMPREPLY=($(compgen -W "${create_opts}" -- "${cur}"))
                fi
            fi
            return
            ;;
    esac

    # ----------------------------------------------------------------------
    # First word: the command.
    # ----------------------------------------------------------------------
    if [ "${COMP_CWORD}" -eq 1 ]; then
        COMPREPLY=($(compgen -W "${commands}" -- "${cur}"))
        return
    fi

    # ----------------------------------------------------------------------
    # Second word: the subcommand.
    # ----------------------------------------------------------------------
    if [ "${COMP_CWORD}" -eq 2 ]; then
        case "${COMP_WORDS[1]}" in
            interface) COMPREPLY=($(compgen -W "set-lan" -- "${cur}")); return ;;
            firewall)  COMPREPLY=($(compgen -W "enable disable reset" -- "${cur}")); return ;;
            backup)    COMPREPLY=($(compgen -W "create restore" -- "${cur}")); return ;;
            config)    COMPREPLY=($(compgen -W "rollback" -- "${cur}")); return ;;
            admin)     COMPREPLY=($(compgen -W "reset-password" -- "${cur}")); return ;;
        esac
        COMPREPLY=()
        return
    fi

    # ----------------------------------------------------------------------
    # Third word: the argument the accepted subcommands take.
    # ----------------------------------------------------------------------
    if [ "${COMP_CWORD}" -eq 3 ]; then
        case "${COMP_WORDS[1]}:${COMP_WORDS[2]}" in
            backup:restore)    COMPREPLY=($(compgen -f -- "${cur}")); return ;;
            interface:set-lan) COMPREPLY=($(compgen -W "192.168.1.1/24" -- "${cur}")); return ;;
        esac
    fi

    COMPREPLY=()
}

complete -F _microctl microctl
