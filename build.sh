#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
mkdir -p dist
stage=$(mktemp -d)
trap 'rm -r "$stage"' EXIT
cp -R woocommerce/paysum-woocommerce wordpress/paysum-checkout "$stage/"
cp shared/Checkout.php "$stage/paysum-woocommerce/"
cp -R invision/applications/paysum "$stage/"
cp shared/Checkout.php "$stage/paysum/"
out=$PWD/dist
(cd "$stage" && zip -q -r paysum-woocommerce.zip paysum-woocommerce &&
    zip -q -r paysum-wordpress.zip paysum-checkout &&
    COPYFILE_DISABLE=1 tar -cf paysum-invision.tar -C paysum Application.php Checkout.php data extensions hooks modules)
mv "$stage"/paysum-woocommerce.zip "$stage"/paysum-wordpress.zip "$stage"/paysum-invision.tar "$out/"
