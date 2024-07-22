#!/bin/bash

#stop service and remove from startup
systemctl stop issabeldialer
chkconfig --del issabeldialer
chkconfig --level 2345 issabeldialer off

#remove folder and files
rm -rf /var/www/html/modules/{agent_console,agents,break_administrator,callcenter_config,calls_detail,calls_per_agent,calls_per_hour}
rm -rf /var/www/html/modules/{campaign_in,campaign_lists,campaign_monitoring,campaign_out,cb_extensions,client,dont_call_list}
rm -rf /var/www/html/modules/{eccp_users,external_url,form_designer,form_list,graphic_calls,hold_time,ingoings_calls_success}
rm -rf /var/www/html/modules/{login_logout,queues,rep_agent_information,rep_agents_monitoring,rep_incoming_calls_monitoring}
rm -rf /var/www/html/modules/{rep_trunks_used_per_hour,reports_break}

#remove dialer
rm -rf /opt/issabel/dialer
rm -rf /etc/rc.d/init.d/issabeldialer
rm -rf /etc/logrotate.d/issabeldialer
rm -rf /usr/bin/issabel-callcenter-local-dnc
rm -rf /usr/share/issabel/module_installer/callcenter/

#remove menu
issabel-menuremove call_center

#remove database
#not recommended..., but you can remove it manually using "mysql -p -u'root' -e'DROP DATABASE call_center;'"
