#!/bin/bash

root=$( dirname $( wp config path ) )

wp theme activate wporg-make-2024

wp rewrite structure '/%postname%/'
wp rewrite flush --hard

wp option update blogname "Make WordPress"
