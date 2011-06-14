
## What It Is

This software is a LiveWhale application module that allows other applications to subscribe to push notifications of updates of news, events and blurbs through a simple callback protocol. It is based on a light version of the [PubSubHubbub protocol](http://code.google.com/p/pubsubhubbub/) akin to that used by [Instagr.am](http://instagram.com/) for their [real-time updates](http://instagram.com/developer/realtime/).

### The Process

Web applications subscribe to updates of a certain type (news, events, blurbs) and an optional group id or tag. From that point forward, as content matching a LiveWhale Push subscription is created, updated or deleted, LiveWhale Push sends a notification to the callback_url provided at the time the subscription was made.

The notification is a JSON object that includes details about the subscription being matched as well as the LiveWhale item id, and if the item is new, was deleted, or what fields changed. It does not send the entire record as in some cases that would be unnecessary.

The application can then react to the notification, obtaining additional data through an API if needed.

## Requirements

### Database Tables

You must add two new tables to your LiveWhale database as follows:

#### API Client Table

    livewhale_apiclients

    id:             integer (11)
    created_at:     datetime
    updated_at:     datetime
    client_id:      varchar (32) latin1_general_ci
    client_secret:  varchar (32) latin1_general_ci
    name:           varchar (100) utf8_general_ci
    email:          varchar (100) latin1_general_ci

    index on:       client_id
    index_on:       client_id_client_secret


#### Subscriptions Table

    livewhale_hubsubscriptions

    id:             integer (11)
    created_at:     datetime
    client_id:      integer (11)
    callback_url:   varchar (255) latin1_general_ci
    object:         varchar (20) latin1_general_ci
    group_id:       integer (11), default: NULL
    tag:            varchar (100) utf8_general_ci, default: NULL

    index on:       object_tag
    index on:       object_group_id
    index_on:       object_group_id_tag
    index on:       client_id

### Dependencies

You must install our [utilities classes](https://github.com/lewisandclark/utilities) to be able to use this module. It provides http status responses and  the inflector, which is used to handle pluralization of tags.

### API

This software only provides the means of updating web applications that an piece of content matching some parameters has changed. As it cannot fathom what your web application might do with this knowledge, it does not send the data itself. For that, you must have an external API available for your use, so that the application can retrieve the full record and react as per it's own code.

## Installation

The easiest way to install this software is to use git to clone it into your livewhale/client/modules folder as follows:

    $ cd /path/to/your/livewhale/client/modules
    $ git clone git://github.com/lewisandclark/push.git

Git will then copy the most current version of the code into a push folder within clients/modules.

If you don't have or are unable to use git, you can also download a zip or tarball from github (use the downloads button) and extract it manually into the livewhale/client/modules folder as push. (Don't change the name, it will make it non-functional in LiveWhale.)

## API Clients

You will need to create API clients by hand in your database as a helper tool is not yet available (planned for a future release). To do this, insert into your livewhale_apiclients a row containing two different 32 character hashes for both the `client_id` and `client_secret` and the obvious other elements.

If you are using MySQL, the following code would work (replace `client_id`, `client_secret`, `name` and `email` with your values):

    INSERT INTO `livewhale_apiclients` (`id`, `created_at`, `updated_at`, `client_id`, `client_secret`, `name`, `email`) VALUES(NULL, NOW(), NOW(), '0123456798abcdef0123456798abcdef', '0123456798abcdef0123456798abcdef', 'Some Application Name', 'contact.or.errors@some.application.com');

## Usage

Once you have everything installed and have created your first API client, you will then be able to use the following usage guide to manage LiveWhale Push notifications.

### Subscriptions

Web applications may use the three `/live/` REST urls to be able to manage their content subscriptions.


