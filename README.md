# teamspeak_updater
Update Linux and Mac Teamspeak server

## Required:
* php >= 7.1
* php-zip
* php-bz2
* php-curl

required and best practice: run teamspeak with a dedicated teamspeak user.   

## configuration

* Edit LOCAL_TS3_LOCATION with your teamspeak installation location like:    
``` /usr/local/bin/teamspeak3-server_linux_amd64 ```

the correct directory contains the ts3server binary.
* TEAMSPEAK_SYSTEM_USER and TEAMSPEAK_SYSTEM_GROUP can be also altered if needed.
* TEMP_DIRECTORY is the location where the newer ts3server is downloaded, afterwords the temp location will be deleted 


## usage 

create a cronjob ``` crontab -e ``` and run the script with root permissions.

the script will handle the correct user and group ifself.   
if an update is available, the avg. downtime for of the server is about 5-10seconds.    
if no update is available nothing happend.     

my cronjob job runs every week on sunday 4am.
