fn main() {
    let php_config = std::env::var("PHP_CONFIG")
        .unwrap_or_else(|_| "php-config".to_string());
    println!("cargo:rerun-if-env-changed=PHP_CONFIG");
    // ext-php-rs build script reads PHP_CONFIG env var directly
    let _ = php_config;
}
