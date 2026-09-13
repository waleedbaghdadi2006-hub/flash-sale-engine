# Flash Sale Engine

A high-performance flash sale engine built with Laravel, Nginx, and Mysql.

## Getting Started

1. Clone the repository.
2. Copy `.env.example` to `.env`.
3. Run `docker-compose up -d`.

## Local payment simulation

Payment is intentionally **mock-only in local/test environments** right now. The customer-facing endpoint `POST /orders/{order}/payments` does not accept a client-supplied amount, payment status, or provider transaction id. The server uses the order total and generates a local transaction id, then confirms the order.

Before deployment, replace this local simulation with a real provider checkout flow and enable the payment webhook only after implementing provider signature verification. The current webhook is deliberately rejected outside local/test environments.
