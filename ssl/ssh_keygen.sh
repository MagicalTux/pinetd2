#!/bin/sh
/usr/bin/ssh-keygen -d -f ssh_host_dsa_key -N ''
/usr/bin/ssh-keygen -t rsa -f ssh_host_rsa_key -N ''
