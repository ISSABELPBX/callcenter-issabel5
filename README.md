```
  ___               _          _ 
 |_ _|___ ___  __ _| |__   ___| |
  | |/ __/ __|/ _` | '_ \ / _ \ |
  | |\__ \__ \ (_| | |_) |  __/ |
 |___|___/___/\__,_|_.__/ \___|_|
```

Issabel is an open source distribution and GUI for Unified Communications systems forked from Elastix&copy;

It uses the [Asterisk©](http://www.asterisk.org/ "Asterisk Home Page") open source PBX software as its core.


Callcenter Issabel 5
==========

Call Center Module for Issabel V5, Updated for installing on rocky 8 , with php compatiblle with v7.4 up to v8.0 , In callback and agent modes. 

esta version puede ser instalada en asterisk 13, 16, 18 o 20 en IssabelPBX.
This repo is tested with Issabel v5 and Asterisk 13, 16, 18 or 20, and it is no logner backward compatible with Issabel 4

#### Version actualizada por la comunidad de Issabel, cualquier duda o problema escribir a https://t.me/IssabelPBXip:
Gracias a la colaboracion de Nicolás Gudiño, Hamza ,Julio pacheco, y comunidad de Issabel en telegram


## Installation Commands
----

```bash
# Full installation (run as root)
cd /usr/src
git clone https://github.com/ISSABELPBX/callcenter-issabel5.git 
cd callcenter-issabel5
# For local installation:
bash build/5.0/install-issabel-callcenter.sh -l
# For production repo installation:
bash build/5.0/install-issabel-callcenter.sh
# To see what the installer does (options, installed files, ECCP TLS certificate):
bash build/5.0/install-issabel-callcenter.sh -h
# To update an existing installation in place. Re-running the installer on an
# older installation also updates in place. Both ask for yes or Y: updated
# files are overwritten, so customizations in them are lost.
# --dry-run shows the plan and changes nothing:
bash build/5.0/update-issabel-callcenter.sh --dry-run
bash build/5.0/update-issabel-callcenter.sh
# To Uninstall:
bash build/5.0/remove-issabel-callcenter.sh

# Service management
systemctl start issabeldialer
systemctl status issabeldialer
systemctl stop issabeldialer
```

License
----

GPLv2 or Later

>This program is free software; you can redistribute it and/or
>modify it under the terms of the GNU General Public License
>as published by the Free Software Foundation; either version 2
>of the License, or (at your option) any later version.

>This program is distributed in the hope that it will be useful,
>but WITHOUT ANY WARRANTY; without even the implied warranty of
>MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
>GNU General Public License for more details.

>You should have received a copy of the GNU General Public License
>along with this program; if not, write to the Free Software
>Foundation, Inc., 51 Franklin Street, Fifth Floor, Bosto
