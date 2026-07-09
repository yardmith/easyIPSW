<div align="center">
  <img src="./frontend/assets/logo.png" alt="Logo" width="100">
  
  # [easyIPSW](https://ipsw.adamenglish.net/)
</div>

A simple and intuitive way to browse through the various propriety, encrypted formats found within IPSWs, right from the comfort of your web browser.

Apple's firmware files contain many encrypted files in several different formats. These require finding decryption keys online, running command-line tools, and oftentimes a fair bit of head banging to view their contents. This project cuts out all of that work and makes it way easier to find what you need, or even just want to take a look at, inside any IPSW, with a familiar file browsing interface.

## Features

- Automatic download and extraction of IPSWs on a remote server, ensuring none of your own bandwidth is used
- Automatic decryption and extraction of disk images for browsing root filesystems and ramdisks
- Download any file/folder, no matter how deep it is within an IPSW's structure
- Preview files without having to download them
- View decryption keys for an IPSW's files (courtesy of [The Apple Wiki](https://theapplewiki.com/))

## Running

### Prerequisites

- Linux x86-64 host
- A web server (NGINX recommended)
- PHP 8.2+
- Composer
- Redis running on port 6379
- Python 3
- SQLite 3
- PHP extensions installed and enabled:
  - `pdo_sqlite`
  - `redis`

  Installation of these extensions is outlined in the next section below.
- Cron (optional)

### Setup

1. Configure your web server:
    - Route all URLs through index.php except /assets and /js
    - Requests with URLs within /assets and /js should be served /frontend/assets and /frontend/js, respectively
    - Requests to /*/ws should be proxied to localhost:8081 and have the upgrade headers set
    - Make sure the `X-Accel-Redirect`/`X-Sendfile` headers are properly accounted for
    - If you're using NGINX, you can use the below config:
      ```
      server {
        listen 80;
        server_name your.domain.com;

        set $root /path/to/cloned/repo;
        root $root;

        index index.php;

        # If you want SSL, uncomment these lines below

        # listen 443 ssl;
        # ssl_certificate /path/to/your/cert.pem;
        # ssl_certificate_key /path/to/your/key.pem;

        location ~ /assets/|/js/ {
          try_files /frontend/$uri /frontend/$uri/ =404;
        }

        location / {
          try_files /none /index.php;
        }

        location = /index.php {
          include snippets/fastcgi-php.conf;
          fastcgi_pass unix:/run/php/php8.2-fpm.sock; # Change PHP version if you need to
        }

        location ~ ^/[^/]+/ws$ {
          proxy_pass http://127.0.0.1:8081;

          proxy_http_version 1.1;
          proxy_set_header Upgrade $http_upgrade;
          proxy_set_header Connection "Upgrade";
          proxy_set_header Host $host;
          proxy_set_header X-Real-IP $remote_addr;

          proxy_read_timeout 3600s;
        }

        location /cache {
          internal;
          alias $root/cache;
        }
      }
      ```
2. Clone the repo:
    ```
    git clone https://github.com/yardmith/easyIPSW
    cd easyIPSW
    ```
3. Install required dependencies:
    ```
    composer install
    ```
4. Set up a Linux group with your web server's user (i.e. www-data) and the user that will run the WebSocket server:
    ```
    sudo groupadd ipsw
    sudo usermod -aG ipsw www-data
    sudo usermod -aG ipsw your-websocket-user
    ```
5. Fix ownership and permissions on the database file:
    ```
    sudo chown :ipsw db.sqlite
    sudo chmod 775 db.sqlite
    ```
6. Install the `redis` PHP extension:
    ```
    sudo pecl install redis
    ```
    This command assumes you have PECL installed, if you do not:

    ```
    sudo apt install php-pear php-dev make build-essential
    ```
    Or you can find another way to install the extension.
7. Enable it by editing your `php.ini` file(s) to add the following line (you will need to do this for both the FPM and CLI versions of PHP):
    ```
    extension=redis.so
    ```
8. Install the `pdo_sqlite` PHP extension:
    ```
    sudo apt install php-sqlite3
    ```
    (Note: you do not need to edit your `php.ini` to enable this extension like with Redis)
9. Install packages needed by Python:
    ```
    sudo apt install python3-venv python3-dev build-essential
    ```
10. Set up a Python virtual environment within the `aea` directory (for AEA decryption to work):
    ```
    cd aea
    python3 -m venv .venv
    source .venv/bin/activate
    ```
11. Install Python packages:
    ```
    pip3 install -r requirements.txt
    ```
12. Deactivate the virtual environment and return to the root directory of the project:
    ```
    deactivate
    cd ..
    ```
13. Configure crontab for periodic cache clearing (optional):
    ```
    crontab -e
    ```
    Add the following line:
    ```
    */15 * * * * /usr/bin/php /path/to/cloned/easyIPSW/cache_purge.php
    ```
    Make sure to change the path to correctly point to the `cache_purge.php` maintenance script in the root of the project.

### Running the WebSocket server

After these steps, you should be able to run the following command to start the WebSocket server:

```
php ws.php
```

If you'd like to keep the server running, you can use something like PM2 or Oxmgr to keep the process alive.

Everything else will be handled by your web server (i.e. NGINX).

## Acknowledgements

This project makes use of numerous command-line utilities for decrypting/extracting various proprietary formats, which are included as compiled binaries inside this repo. These include:

- Several tools found within [XPwn](https://github.com/planetbeing/xpwn), created by [planetbeing](https://github.com/planetbeing):
  - `xpwntool`, for decrypting/extracting IMG2 and IMG3 files (fixed by [dora2ios](https://github.com/dora2ios))
  - `imagetool`, for converting older iBoot images to PNGs (also fixed by dora2ios)
  - `dmg`, for decrypting root filesystem DMGs
- [`ibootim`](https://github.com/realnp/ibootim), for converting newer iBoot images to PNGs, created by [npupyshev](https://github.com/realnp)
- [`img4lib`](https://github.com/xerub/img4lib), for decrypting/extracting IMG4 files, created by [xerub](https://github.com/xerub)
- [`pngdefry`](https://github.com/esjeon/pngdefry), for normalizing Apple's special, optimized PNGs, created by [esjeon](https://github.com/esjeon)

For decryption of AEA (Apple Encrypted Archive) files found in newer IPSWs:

- `get_key.py` (part of [aeota](https://github.com/dhinakg/aeota)), for grabbing decryption keys from Apple's server, created by [Dhinak G](https://github.com/dhinakg)
- [`python-aea`](https://github.com/kinnay/AEA), for the actual decryption of these files, created by [Yannik Marchand](https://github.com/kinnay)

This project also uses a few more commonplace command-line tools:

- [`7zz`](https://www.7-zip.org/), for extracting DMGs, created by Igor Pavlov
- [`aria2`](https://github.com/aria2/aria2), for downloading huge IPSWs in less than a minute, created by Tatsuhiro Tsujikawa
- [`ripunzip`](https://github.com/GoogleChrome/ripunzip), for extracting large IPSWs much quicker, created by Adrian Taylor

Finally, I'd like to express my immense gratidude towards [The Apple Wiki](https://theapplewiki.com/) for being a fantastic source of knowledge, as well as maintaining a publicly accessible database of decryption keys for the community to use. This project would not have been possible without them.
