EXT := target/release/libreprint_native.so

.PHONY: all release test bench clean

all: release

release: $(EXT)

$(EXT):
	./build.sh --release

test:
	./build.sh --release && cargo test

bench: $(EXT)
	php -d extension_dir=target/release -d extension=libreprint_native.so bench.php

clean:
	cargo clean
