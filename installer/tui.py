#!/usr/bin/env python3
"""
Micron Firewall OS (MicronOS) — Interactive Console TUI Installer
==================================================================
Curses / Dialog-driven console installation wizard for appliance hardware deployment.
"""

import os
import subprocess
import sys
import time

def clear_screen():
    os.system('clear')

def run_cmd(cmd):
    return subprocess.run(cmd, shell=True, capture_output=True, text=True)

def main_menu():
    clear_screen()
    print("=" * 70)
    print("        MICRON FIREWALL OS (Micron OS) APPLIANCE SETUP WIZARD")
    print("=" * 70)
    print(" 1) Install Micron OS to Disk (Guided Partitioning & Formatting)")
    print(" 2) Configure Network Interfaces (Assign WAN / LAN)")
    print(" 3) Reset Admin Password (Reset 'Ministrator' to Factory Default)")
    print(" 4) Launch Recovery Shell (Bash)")
    print(" 5) Reboot Appliance")
    print(" 6) Power Off Appliance")
    print("=" * 70)
    
    choice = input("Enter choice [1-6]: ").strip()
    return choice

def main():
    while True:
        choice = main_menu()
        if choice == '1':
            if os.path.exists("/usr/local/micron/installer/install.sh"):
                subprocess.run(["bash", "/usr/local/micron/installer/install.sh"])
            elif os.path.exists("./install.sh"):
                subprocess.run(["bash", "./install.sh"])
            else:
                print("Installer script not found.")
                input("Press Enter to continue...")
        elif choice == '2':
            clear_screen()
            print("--- Network Interfaces ---")
            subprocess.run(["ip", "-br", "link", "show"])
            input("\nPress Enter to return...")
        elif choice == '3':
            clear_screen()
            print("Resetting 'Ministrator' password to 'M1Cr0N'...")
            # Run php spark reset if available
            subprocess.run(["php", "spark", "user:reset-admin"], cwd="/usr/local/micron")
            input("\nAdmin password reset. Press Enter...")
        elif choice == '4':
            clear_screen()
            print("Launching emergency shell. Type 'exit' to return to menu.")
            subprocess.run(["/bin/bash"])
        elif choice == '5':
            subprocess.run(["reboot"])
        elif choice == '6':
            subprocess.run(["poweroff"])
        else:
            print("Invalid selection.")
            time.sleep(1)

if __name__ == "__main__":
    if os.geteuid() != 0:
        print("Error: Installer must be run as root.")
        sys.exit(1)
    main()
