# /etc/profile.d/micron-prompt.sh
# Micron OS Console Shell Prompt Configuration

if [ "${EUID:-$(id -u)}" -eq 0 ]; then
    # Root prompt in Bold Red
    PS1='\[\033[01;31m\][MicronOS] \w # \[\033[00m\]'
else
    # Standard operator prompt (ministrator)
    PS1='[MicronOS] \w # '
fi
export PS1
