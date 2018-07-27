```
  ___               _          _ 
 |_ _|___ ___  __ _| |__   ___| |
  | |/ __/ __|/ _` | '_ \ / _ \ |
  | |\__ \__ \ (_| | |_) |  __/ |
 |___|___/___/\__,_|_.__/ \___|_|
```

Issabel is an open source distribution and GUI for Unified Communications systems forked from Elastix&copy;

It uses the [Asterisk©](http://www.asterisk.org/ "Asterisk Home Page") open source PBX software as its core.

Call Center
----

Call Center module for Issabel. Changes to make it work with Asterisk 13


Manual Modifications for Testing
----

In order to test, some changes are needed in the Asterisk dialplan, in
/etc/asterisk/extensions_custom.conf create a new context:

```
[agents]
exten = _X.,1,NoOp()
same = n,AgentRequest(${EXTEN})
same = n,Congestion()
```

This context will be used to dial to a particular logged in Agent.

Then in agents.conf you must use this format for agents:

```
[1000]
fullname=Pedro
autologoff=15
ackcall=yes
acceptdtmf=##
```

Finally you should add agents into queues with a special format. From
the Asterisk CLI you can try something like this:

```
queue add member Local/1000@agents/n to 2000 penalty 0 as "Agente 1000" state_interface Agent:1000
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
>Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
