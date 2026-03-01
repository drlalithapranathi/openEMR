#!/bin/bash
BROWSE=/var/www/localhost/htdocs/openemr/templates/super/rules/controllers/browse
CONTAINER=oemr-openemr-1
SRC=$(cd "$(dirname "$0")" && pwd)

sudo docker cp $SRC/list.php $CONTAINER:$BROWSE/list.php
echo "list.php copied"

sudo docker cp $SRC/cds_proxy.php $CONTAINER:$BROWSE/cds_proxy.php
echo "cds_proxy.php copied"

sudo docker exec $CONTAINER ls $BROWSE
echo "Done!"
