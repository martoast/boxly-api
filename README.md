# Envios API

A Laravel-based API for package consolidation and shipping management system.

## Requirements

- PHP 8.1+
- Composer
- Docker & Docker Compose (for local development with Laravel Sail)
- MySQL 8.0+
- Stripe Account (for payment processing)

## Installation & Setup

### 1. Clone the repository

```bash
git clone <your-repository-url>
cd envios-api
```

### 2. Install dependencies

```bash
composer install
```

### 3. Environment setup

```bash
# Copy the example environment file
cp .env.example .env

# Generate application key
php artisan key:generate
```

### 4. Configure your .env file

Update the following variables in your `.env` file:

```env
# Database
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=laravel
DB_USERNAME=sail
DB_PASSWORD=password

# Application URLs
APP_URL=http://localhost:8001
APP_FRONTEND_URL=http://localhost:3000

# Stripe Configuration
STRIPE_KEY=your_stripe_publishable_key
STRIPE_SECRET=your_stripe_secret_key
STRIPE_WEBHOOK_SECRET=your_stripe_webhook_secret

# Currency Settings
CASHIER_CURRENCY=mxn
CASHIER_CURRENCY_LOCALE=es_MX

# Google OAuth (if using)
GOOGLE_CLIENT_ID=your_google_client_id
GOOGLE_CLIENT_SECRET=your_google_client_secret
```

### 5. Start Docker containers (using Laravel Sail)

```bash
./vendor/bin/sail up -d
```

### 6. Run database migrations

```bash
./vendor/bin/sail artisan migrate
```

### 7. Create storage link (IMPORTANT!)

```bash
./vendor/bin/sail artisan storage:link
```

This command is crucial for proper file handling and must be run after cloning or setting up the project.

### 8. Set proper permissions

```bash
./vendor/bin/sail exec laravel.test chmod -R 777 storage bootstrap/cache
```

### 9. Clear all caches

```bash
./vendor/bin/sail artisan optimize:clear
```

## Common Docker/Sail Commands

```bash
# Start containers
./vendor/bin/sail up -d

# Stop containers
./vendor/bin/sail down

# View logs
./vendor/bin/sail logs -f

# Run artisan commands
./vendor/bin/sail artisan [command]

# Run composer commands
./vendor/bin/sail composer [command]

# Access the container shell
./vendor/bin/sail shell
```

## Troubleshooting

### CORS Issues

If you encounter CORS errors, ensure your `config/cors.php` includes all necessary paths:

```php
'paths' => ['*'],
'allowed_origins' => ['http://localhost:3000'],
'supports_credentials' => true,
```

Then clear config cache:
```bash
./vendor/bin/sail artisan config:clear
```

### Storage Link Issues

If you're getting 500 errors or storage-related issues, ensure you've run:
```bash
./vendor/bin/sail artisan storage:link
```

### Logging Issues

Check log permissions:
```bash
./vendor/bin/sail exec laravel.test chmod -R 777 storage/logs
```

View logs:
```bash
./vendor/bin/sail exec laravel.test tail -f storage/logs/laravel.log
```

### Cache Issues

Clear all caches:
```bash
./vendor/bin/sail artisan cache:clear
./vendor/bin/sail artisan config:clear
./vendor/bin/sail artisan route:clear
./vendor/bin/sail artisan view:clear
```

## API Endpoints

### Authentication
- `GET /sanctum/csrf-cookie` - Get CSRF cookie
- `POST /login` - User login
- `POST /logout` - User logout

### Products
- `GET /products` - List all available box products

### Orders
- `POST /checkout` - Create Stripe checkout session
- `GET /orders` - List user orders
- `GET /orders/{id}` - Get order details
- `PUT /orders/{id}` - Update order
- `POST /orders/{id}/items` - Add item to order

### Profile
- `GET /profile` - Get user profile
- `PUT /profile` - Update user profile

### Admin Routes
All admin routes require authentication and admin role:
- `GET /admin/dashboard` - Admin dashboard
- `GET /admin/orders` - List all orders
- `PUT /admin/orders/{id}/status` - Update order status

## Stripe Webhook Setup

1. Go to Stripe Dashboard → Developers → Webhooks
2. Add endpoint: `http://your-domain.com/webhooks/stripe`
3. Select events:
   - `checkout.session.completed`
   - `payment_intent.succeeded`
4. Copy the signing secret to `STRIPE_WEBHOOK_SECRET` in your `.env`

## Testing

```bash
# Run tests
./vendor/bin/sail artisan test

# Run specific test
./vendor/bin/sail artisan test --filter=TestName
```

## Production Deployment

1. Set `APP_ENV=production` and `APP_DEBUG=false`
2. Run `php artisan config:cache`
3. Run `php artisan route:cache`
4. Ensure proper file permissions
5. Set up a process manager like Supervisor for queue workers
6. Configure your web server (Nginx/Apache) to point to the `public` directory

---

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).