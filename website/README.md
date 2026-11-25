# Yes Enterprise application

This directory contains the Laravel public website and Filament admin panel.
See the [repository README](../README.md) for Docker setup, local login,
validation, private storage and production prerequisites.

The public site uses Blade and local CSS/JS, without a required Vite build.
Filament assets are published by Composer's post-autoload script.
Laravel is MIT-licensed; the separate Industro license and visible author
attribution are retained under `public/vendor/industro`.
