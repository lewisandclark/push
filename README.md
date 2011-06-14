
## What It Is

This software is a LiveWhale application module that allows other applications to subscribe to updates of news, events and blurbs through a simple callback protocol. It is based on a light version of the [PubSubHubbub protocol](http://code.google.com/p/pubsubhubbub/) akin to that used by [Instagr.am](http://instagram.com/) for their [real-time updates](http://instagram.com/developer/realtime/).

## Requirements

### Database Tables

You must add two new tables to your LiveWhale database as follows:

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

  livewhale_hubsubscriptions
    id:             integer (11)
    created_at:     datetime
    client_id:      integer (11)
    callback_url:   varchar (255) latin1_general_ci
    object:         varchar (20) latin1_general_ci
    group_id:       integer (11)
    tag:            varchar (100) utf8_general_ci

    index on:       object_tag
    index on:       object_group_id
    index_on:       object_group_id_tag
    index on:       client_id

### Dependencies

You must install the [utilities classes](https://github.com/lewisandclark/utilities) to be able to use this module. It provides http status responses and  the inflector, which is used to handle pluralization of tags.

## Installation




