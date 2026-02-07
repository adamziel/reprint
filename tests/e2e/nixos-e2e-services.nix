{ config, pkgs, lib, ... }:

let
  # PHP with required extensions
  phpPackage = pkgs.php82.withExtensions ({ enabled, all }: enabled ++ [
    all.pdo_mysql
    all.zlib
    all.curl
    all.mbstring
    all.openssl
    all.simplexml
    all.tokenizer
    all.filter
    all.ctype
  ]);

  siteRoot = "/srv/e2e-sites";

  # All test sites with their nginx configurations
  # Each site gets a unique port on 127.0.0.1
  testSites = {
    basic               = { port = 8081; };
    symlinks-outside    = { port = 8082; };
    custom-wp-content   = { port = 8083; };
    chmod-denied        = { port = 8084; };
    mysql-restricted    = { port = 8085; };
    circular-symlinks   = { port = 8086; };
    file-changes        = { port = 8087; };
    dir-deleted         = { port = 8088; };
    volatile-file       = { port = 8089; };
    emoji-paths         = { port = 8090; };
    large-directory     = { port = 8091; };
    hmac-errors         = { port = 8092; };
    sha1-verify         = { port = 8093; };

    # Error simulation sites
    http-errors         = { port = 8094; };
    request-cutoff      = { port = 8095; };
    gzip-corrupt        = { port = 8096; };
    redirect-301        = { port = 8097; };
    buffered            = { port = 8098; };
    error-chunks        = { port = 8099; };
    import-failures     = { port = 8100; };
  };

  # Generate Nginx virtualHost config for each site
  makeVhost = name: cfg: {
    name = "e2e-${name}";
    value = {
      listen = [{ addr = "127.0.0.1"; port = cfg.port; }];
      root = "${siteRoot}/${name}/wp-content/plugins/site-export";
      locations = {
        "/" = {
          tryFiles = "$uri $uri/ /api.php?$query_string";
        };
        "~ \\.php$" = {
          extraConfig = ''
            fastcgi_pass unix:${config.services.phpfpm.pools.e2e.socket};
            fastcgi_index api.php;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            include ${pkgs.nginx}/conf/fastcgi_params;
            fastcgi_param SITE_EXPORT_TEST_MODE "1";
            fastcgi_read_timeout 120s;
            fastcgi_send_timeout 120s;
          '';
        };
      };
    };
  };

  # Special config for buffered site (tests proxy buffering)
  bufferedVhost = {
    name = "e2e-buffered";
    value = {
      listen = [{ addr = "127.0.0.1"; port = 8098; }];
      root = "${siteRoot}/buffered/wp-content/plugins/site-export";
      locations = {
        "/" = {
          tryFiles = "$uri $uri/ /api.php?$query_string";
        };
        "~ \\.php$" = {
          extraConfig = ''
            fastcgi_pass unix:${config.services.phpfpm.pools.e2e.socket};
            fastcgi_index api.php;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            include ${pkgs.nginx}/conf/fastcgi_params;
            fastcgi_param SITE_EXPORT_TEST_MODE "1";
            fastcgi_read_timeout 120s;
            # Enable proxy buffering to simulate Apache-like buffering
            fastcgi_buffering on;
            fastcgi_buffer_size 128k;
            fastcgi_buffers 8 128k;
          '';
        };
      };
    };
  };

  # 301 redirect site
  redirectVhost = {
    name = "e2e-redirect-301";
    value = {
      listen = [{ addr = "127.0.0.1"; port = 8097; }];
      locations = {
        "/" = {
          return = "301 http://127.0.0.1:8081$request_uri";
        };
      };
    };
  };

  allVhosts = builtins.listToAttrs (
    (lib.mapAttrsToList makeVhost (builtins.removeAttrs testSites ["buffered" "redirect-301"]))
    ++ [ bufferedVhost redirectVhost ]
  );

in {
  # MariaDB (MySQL-compatible)
  services.mysql = {
    enable = true;
    package = pkgs.mariadb;
    settings = {
      mysqld = {
        bind-address = "127.0.0.1";
        max_allowed_packet = "64M";
        innodb_buffer_pool_size = "256M";
      };
    };
    ensureDatabases = [];
    ensureUsers = [
      {
        name = "e2e_admin";
        ensurePermissions = {
          "*.*" = "ALL PRIVILEGES";
        };
      }
      {
        name = "e2e_restricted";
        ensurePermissions = {
          "e2e_mysql_restricted.*" = "SELECT";
        };
      }
    ];
  };

  # PHP-FPM pool for e2e tests
  services.phpfpm.pools.e2e = {
    user = "nginx";
    group = "nginx";
    phpPackage = phpPackage;
    settings = {
      "listen.owner" = "nginx";
      "listen.group" = "nginx";
      "pm" = "dynamic";
      "pm.max_children" = 20;
      "pm.start_servers" = 4;
      "pm.min_spare_servers" = 2;
      "pm.max_spare_servers" = 8;
      "php_admin_value[memory_limit]" = "512M";
      "php_admin_value[max_execution_time]" = "120";
      "php_admin_value[upload_max_filesize]" = "50M";
      "php_admin_value[post_max_size]" = "50M";
      "php_admin_value[error_reporting]" = "E_ALL";
      "php_admin_value[display_errors]" = "Off";
      "php_admin_value[log_errors]" = "On";
      "php_admin_value[error_log]" = "/tmp/php-e2e-errors.log";
    };
    phpEnv = {
      SITE_EXPORT_TEST_MODE = "1";
    };
  };

  # Nginx
  services.nginx = {
    enable = true;
    recommendedProxySettings = true;
    clientMaxBodySize = "50m";
    virtualHosts = allVhosts;
  };

  # Open firewall ports for test sites
  networking.firewall.allowedTCPPorts =
    lib.mapAttrsToList (_: cfg: cfg.port) testSites;

  # Ensure site directory exists
  systemd.tmpfiles.rules = [
    "d ${siteRoot} 0755 nginx nginx -"
  ];

  # Make PHP CLI available
  environment.systemPackages = [
    phpPackage
    phpPackage.packages.composer
    pkgs.mariadb
  ];
}
