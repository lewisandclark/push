
## What It Is

This software is a LiveWhale application module that allows other applications to subscribe to updates of news, events and blurbs through a simple callback protocol. It is based on a light version of the [PubSubHubbub protocol](http://code.google.com/p/pubsubhubbub/) akin to that used by [Instagr.am](http://instagram.com/) for their [real-time updates](http://instagram.com/developer/realtime/).

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

## Installation

The easiest way to install this software is to use git to clone it into your livewhale/client/modules folder as follows:

    $ cd /path/to/your/livewhale/client/modules
    $ git clone git://github.com/lewisandclark/pubsubhub.git

Git will then copy the most current version of the code into a pubsubhub folder within clients/modules.

## API Clients

You will need to create API clients by hand in your database as a helper tool is not yet available (planned for a future release). To do this, insert into your livewhale_apiclients a row containing two different 32 character hashes for both the `client_id` and `client_secret` and the obvious other elements.

If you are using MySQL, the following code would work:

    INSERT INTO `livewhale_apiclients` (`id`, `created_at`, `updated_at`, `client_id`, `client_secret`, `name`, `email`) VALUES(NULL, NOW(), NOW(), '0123456798abcdef0123456798abcdef', '0123456798abcdef0123456798abcdef', 'Some Application Name', 'contact.or.errors@some.application.com');


