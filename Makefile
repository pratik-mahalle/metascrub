# SPDX-FileCopyrightText: 2026 Metadata Scrubber contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

app_name := metascrub
build_dir := $(CURDIR)/build
artifacts := $(build_dir)/artifacts
cert_dir := $(HOME)/.nextcloud/certificates

.PHONY: build test appstore sign clean

build:
	npm ci
	npm run build

test:
	composer install --no-interaction
	composer test:unit

# Release tarball for the Nextcloud App Store (only runtime files).
appstore: build
	rm -rf $(build_dir)/stage $(artifacts)
	mkdir -p $(build_dir)/stage/$(app_name) $(artifacts)
	cp -r appinfo lib js LICENSE README.md CHANGELOG.md $(build_dir)/stage/$(app_name)/
	find $(build_dir)/stage/$(app_name)/js -name '*.map' -delete
	tar -czf $(artifacts)/$(app_name).tar.gz -C $(build_dir)/stage $(app_name)
	@echo "Built $(artifacts)/$(app_name).tar.gz"

# Signature to paste into the App Store release form.
sign:
	openssl dgst -sha512 -sign $(cert_dir)/$(app_name).key $(artifacts)/$(app_name).tar.gz | openssl base64

clean:
	rm -rf $(build_dir) js node_modules vendor
