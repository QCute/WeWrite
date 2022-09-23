
# WeWrite
Collaborative markdown editor

# Requirements
* [PHP](https://github.com/php) >= 7.0.0
* PHP Pecl [Swoole](https://github.com/swoole) Extension
* [Editor.md](https://github.com/pandao/editor.md) markdown editor

# Installation and Run

Install
```sh
git clonet https://github.com/QCute/WeWrite.git
cd WeWrite
git submodule init
git submodule update --recursive
```

Run
```sh
php server.php --port=8080 --cookie=we-write
```

# Usage
Open http://localhost:8080/ in browser.

# Share
Copy the url, send to other user.
