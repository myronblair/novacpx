# Third-party notices

NovaCPX is an independent project. Its code, user interface (the `nova.css` design system), icons and documentation were written for NovaCPX and are not copied from any other control panel. Other panels were only studied for the general list of features hosting customers expect; feature ideas are not code.

NovaCPX installs and talks to these open-source programs, each under its own licence (they are installed from the operating system's package repositories or the vendors' own installers, they are not bundled in this repository):

| Software | Used for |
|---|---|
| nginx / Apache HTTP Server | web server |
| PHP-FPM | PHP hosting |
| MariaDB / MySQL, PostgreSQL, SQLite | databases |
| Postfix, Dovecot, OpenDKIM | email |
| BIND 9 | DNS |
| ProFTPD, vsftpd, Pure-FTPd | FTP |
| Roundcube | webmail |
| certbot (Let's Encrypt) | TLS certificates |
| Fail2Ban, UFW | intrusion blocking and firewall |
| Docker Engine and Compose | container hosting |
| rclone | remote backups |

Product and company names are trademarks of their respective owners; mentioning them (for example to say a feature works with WHMCS billing or that a certificate comes from Let's Encrypt) does not imply any affiliation.

Before distributing NovaCPX, add a `LICENSE` file for the NovaCPX code itself (the repository does not have one yet) and re-check this list against `install.sh`.
