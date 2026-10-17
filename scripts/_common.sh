#!/bin/bash

# The web client itself (daemon + static UI) ships in sources/app and is copied
# verbatim: it must NOT go through ynh_config_add, whose __VAR__ substitution
# would choke on PHP's __DIR__ & co. Only conf/config.php is a template.
madeline_setup_app() {
    local dir
    for dir in bin src www vendor; do
        ynh_safe_rm "$install_dir/$dir"
    done
    cp -r "$YNH_APP_BASEDIR/sources/app/." "$install_dir/"

    madeline_set_permissions
    ynh_composer_exec install --no-dev --optimize-autoloader --no-progress
    # The download cache is only needed during install/upgrade
    ynh_safe_rm "$install_dir/.composer/cache"
    madeline_set_permissions
}

madeline_add_config() {
    # 600, owned by $app (ynh_config_add's default for files in $install_dir):
    # it contains the api_hash
    ynh_config_add --template="config.php" --destination="$install_dir/config.php"
}

# nginx (www-data) only needs the static UI; sessions and logs are $app-only.
madeline_set_permissions() {
    chown -R "$app:www-data" "$install_dir"
    chmod -R u=rwX,g=rX,o= "$install_dir"
    [ ! -e "$install_dir/config.php" ] || chmod 600 "$install_dir/config.php"

    mkdir -p "$data_dir/sessions" "$data_dir/tmp"
    chown -R "$app:$app" "$data_dir"
    chmod -R u=rwX,go= "$data_dir"

    mkdir -p "/var/log/$app"
    chown -R "$app:$app" "/var/log/$app"
    chmod 750 "/var/log/$app"
}

madeline_add_service() {
    ynh_config_add --template="tmpfiles.conf" --destination="/etc/tmpfiles.d/$app.conf"
    systemd-tmpfiles --create "/etc/tmpfiles.d/$app.conf"
    ynh_config_add_systemd
    ynh_config_add_logrotate "/var/log/$app"
    yunohost service add "$app" \
        --description "Telegram web client (MadelineProto)" \
        --log "/var/log/$app/$app.log" "/var/log/$app/madelineproto.log"
}
