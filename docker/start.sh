   #!/bin/sh
   # Render assigns a port via $PORT; make Apache listen on it
   sed -i "s/Listen 80/Listen ${PORT:-80}/" /etc/apache2/ports.conf
   sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT:-80}>/" /etc/apache2/sites-available/000-default.conf

   php artisan config:cache
   php artisan route:cache
   php artisan storage:link || true
   php artisan migrate --force

    # Queue worker, restarted automatically if it exits
    (while true; do
    runuser -u www-data -- php artisan queue:work --tries=3 --sleep=3 --max-time=3600
    sleep 1
    done) &

    # Scheduler, restarted automatically if it exits
    (while true; do
    runuser -u www-data -- php artisan schedule:work
    sleep 1
    done) &

    # Queue worker, restarted automatically if it exits
    (while true; do
    runuser -u www-data -- php artisan queue:work --tries=3 --sleep=3 --max-time=3600
    sleep 1
    done) &

    # Scheduler, restarted automatically if it exits
    (while true; do
    runuser -u www-data -- php artisan schedule:work
    sleep 1
    done) &

   apache2-foreground
