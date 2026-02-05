# DataTable Helper – Kimsang

A reusable Laravel DataTable helper package designed for **searching, sorting, and pagination**, with **PostgreSQL-safe global search**, integer compatibility, and operator-based filtering.

This package is built for real-world admin panels, API backends, and frontend data tables.

---

## ✨ Features

- ✅ Laravel 10 & 11 compatible
- ✅ PostgreSQL-safe (`ILIKE`, reserved keyword handling)
- ✅ Works with **string & integer columns**
- ✅ Operator-based global search
- ✅ Safe parameter binding (SQL injection protected)
- ✅ Supports Eloquent Model, Builder, or Model instance
- ✅ Ready for Composer & Packagist

---

### Search Application Logic

The `applySearch` method applies search conditions dynamically based on a
specified operator. It supports both text and numeric columns by safely
casting values to text when necessary and performs case-insensitive matching
using PostgreSQL `ILIKE`.

This approach allows a single global search to work across mixed column types
while remaining safe and performant.

## 📦 Installation

```bash
composer require data-table/kimsang
```
