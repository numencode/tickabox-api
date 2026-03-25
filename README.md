# Tickabox API

Backend API for the **Tickabox** mobile application built with **Laravel**.

This repository contains the server-side application that powers the NativePHP Mobile app:

**Tickabox mobile app:** https://github.com/numencode/tickabox

---

## Requirements

- PHP 8.3 or newer
- Composer 2.3 or newer
- MariaDB 10.6+ or MySQL 8+ 

---

## Overview

Tickabox API is responsible for:

- user registration and login
- API token authentication via **Laravel Sanctum**
- secure access to authenticated user data
- receiving synced todo changes from the mobile app
- returning remote changes back to the mobile app
- storing synced data in a **MariaDB / MySQL** database

The mobile application uses **SQLite** for local offline-first storage, while this API uses **MySQL** as the 
central backend database. Todo changes created on the device are synced between the mobile app and this API.

---

## Tech Stack

- PHP
- Laravel
- Laravel Sanctum
- MariaDB / MySQL
- Pest (feature tests)

---

## Related Project

This API is designed to work together with the Tickabox NativePHP Mobile application:

**Mobile app repository:** https://github.com/numencode/tickabox

---

## Authentication

The mobile application authenticates against this API using **Laravel Sanctum**.

The API provides endpoints for:

- register
- login
- logout
- current authenticated user
- todo sync push
- todo sync pull

Protected endpoints are secured with Sanctum token authentication.

---

## Sync Architecture

Tickabox uses an **offline-first** approach.

### Mobile app
- stores todos locally in **SQLite**
- records local changes in a sync queue/outbox
- pushes changes to this API when syncing
- pulls remote updates back from the API

### API
- stores synced todo data in **MariaDB / MySQL**
- accepts incoming todo operations from the mobile app
- returns remote changes for pull sync
- keeps each user’s data isolated

This setup allows the mobile app to remain usable offline 
while still syncing with the backend when connectivity is available.

---

## Installation

Clone the repository and create your environment file:

```bash
cp .env.example .env
```

Install dependencies:

```bash
composer install
```

Generate the application key:

```bash
php artisan key:generate
```

Configure your **MariaDB / MySQL** connection in `.env`, then run migrations:

```bash
php artisan migrate
```

If needed, start the local development server:

```bash
php artisan serve
```

---

## Environment

At minimum, configure these values in your `.env` file:

```env
APP_NAME=Tickabox API
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tickabox_api
DB_USERNAME=root
DB_PASSWORD=
```

You should also configure any production-specific values such as:

* `APP_ENV=production`
* `APP_DEBUG=false`
* HTTPS-enabled `APP_URL`
* cache / queue / session settings as needed

---

## Running Tests

This project includes **Feature tests** written with **Pest**.

Run the full test suite with:

```bash
php artisan test
```

---

## API Purpose

This application is intended to be used as the backend server for Tickabox mobile clients.

It is not designed as a web-facing application with a traditional frontend. 
Its primary purpose is to expose a clean authenticated API for the mobile app.

---

## Security Notes

* authenticated routes are protected with **Laravel Sanctum**
* API rate limiting can be applied to authentication and sync routes
* production deployments should always use **HTTPS**
* user data is scoped to the authenticated user

---

## Author

The **NumenCode Tickabox API** is created by [Blaz Orazem](https://orazem.si).

For inquiries, contact: [info@numencode.com](mailto:info@numencode.com)

---

## License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
