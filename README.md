
## What It Is

This software is a LiveWhale application module that allows other applications to subscribe to push notifications of updates of news, events and blurbs through a simple callback protocol. It is based on a light version of the [PubSubHubbub protocol](http://code.google.com/p/pubsubhubbub/) akin to that used by [Instagr.am](http://instagram.com/) for their [real-time updates](http://instagram.com/developer/realtime/).

### The Process

Web applications subscribe to updates of a certain type (news, events, blurbs) and an optional group id and/or tag. From that point forward, as content matching a LiveWhale Push subscription is created, updated or deleted, LiveWhale Push sends a notification to the callback_url provided at the time the subscription was made.

The notification is a JSON array of notification objects each including details about the subscription being matched as well as the LiveWhale item id, and if the item is new, was deleted, or what fields changed. It does not send the entire record as in some cases that would be unnecessary.

The application can then react to the notification, obtaining additional data through an external API if needed.

## Requirements

### LiveWhale

Although this software might work with earlier versions, it has only been tested with LiveWhale 1.4.1 or better.

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

This software only provides the means of updating web applications that an piece of content matching some parameters has changed. As it cannot fathom what your web application might do with this knowledge, it does not send the data itself. There is one built in option for retrieving key fields that are normally available through widgets (outlined in Notifications > Content Retrieval below).

However, if you need a greater level of data, or access to data that is not currently live (i.e. hidden or scheduled), there isn't an official LiveWhale API yet. For our use, we have simply grafted a Rails app onto the LiveWhale tables as a read-only REST interface. (LiveWhale, the Rails app and our database are all on the same server, but it need not be that way.) I'd recommend this path as a temporary solution if you know Rails, as it only takes a little while to setup.

## Installation

The easiest way to install this software is to use git to clone it into your livewhale/client/modules folder as follows:

    $ cd /path/to/your/livewhale/client/modules
    $ git clone git://github.com/lewisandclark/push.git

Git will then copy the most current version of the code into a push folder within client/modules.

If you don't have or are unable to use git, you can also download a zip or tarball from github (use the downloads button) and extract it manually into the livewhale/client/modules folder as push. (Don't change the name, it will make it non-functional in LiveWhale.)

## API Clients

You will need to create API clients by hand in your database as a helper tool is not yet available (planned for a future release). To do this, insert into your livewhale_apiclients a row containing two different 32 character hashes for both the `client_id` and `client_secret` and the obvious other elements.

If you are using MySQL, the following code would work (replace `client_id`, `client_secret`, `name` and `email` with your values):

    INSERT INTO `livewhale_apiclients` (`id`, `created_at`, `updated_at`, `client_id`, `client_secret`, `name`, `email`) VALUES(NULL, NOW(), NOW(), '0123456798abcdef0123456798abcdef', '0123456798abcdef0123456798abcdef', 'Some Application Name', 'contact.or.errors@some.application.com');

## Usage

Once you have everything installed and have created your first API client, you will then be able to use the following usage guide to manage LiveWhale Push notifications.

### Subscription

Web applications may use the three `/live/` REST urls to be able to manage their content subscriptions.

#### Subscribe

To create a subscription, you will need to POST (at minimum) your `client_id`, `client_secret`, `object` and `callback_url` to the `/live/subscribe` url. (Https is recommended to maintain the security of your `client_secret`, but this is not enforced.) You may additionally add a `group_id`, a `tag` and a `verify_token`.

Please note:

  * Tag requests are automatically canonicalized; tags are reduced to the singular form or the word, all lower case.
  * Remember to encode your callback_url if non-basic-latin characters are present.
  * The `verify_token` can be used by you to differentiate similar subscriptions. It will be returned with the callback test only. It is not stored.

If your application does not need active management of subscriptions (i.e. they don't change often) then you can simply use cURL to manage them from the command line. An example follows:

    curl \
      -F 'client_id=0123456798abcdef0123456798abcdef' \
      -F 'client_secret=0123456798abcdef0123456798abcdef' \
      -F 'object=news' \
      -F 'tag=audio' \
      -F 'group_id=' \
      -F 'verify_token=some-verification-token' \
      -F 'callback_url=http://some.application.com/subscription/callback' \
      https://your.livewhale.com/live/subscribe/

LiveWhale Push will call your callback as part of the subscription process to test it. This will be a GET request with a `hub_challenge` parameter. (Actual notifications arrive as POSTs, see below.) At minimum, all your web application needs to do to verify the callback for LiveWhale Push is return the `hub_challenge` in the body of the response.

If you wish, you can authenticate the request using the `verify_token` or handle additional issues before returning the `hub_challenge`. If you need or want the highest level of security, you can confirm that the `hub_challenge` matches the SHA1 digest of your initial subscription request ip address and your `callback_url` combined in that order with two dashes between, and encrypted with your `client_secret` as the secret.

If your subscription request is successfully created, you will receive a JSON array with a single subscription object. The subscription object (shown below) contains the subscription `id` and your `client_id`, as well as the `object`, `group_id` and `tag` for the subscription (regardless of whether they were specified or not). Otherwise, you will receive a http status code error along with an error message as to the problem encountered.

    [
      {
        id: 9,
        client_id: '0123456798abcdef0123456798abcdef',
        object: 'news',
        group_id: '',
        tag: 'audio'
      }
    ]

Excluding `verify_token`, if you attempt to create another subscription with the same parameters, LiveWhale Push will merely return the original subscription JSON as above. Any change in any parameter will then create a new subscription. (In other words, subscriptions cannot be updated; they can only be created and deleted.)

#### Unsubscribe

To unsubscribe from LiveWhale Push, submit a POST request to `/live/unsubscribe` with your `client_id`, `client_secret` and `id`, where `id` is the subscription id you wish to terminate. (Https is recommended for security, but not required.) Again, unless your application requires active management of the subscriptions, you can use cURL to handle this:

    curl \
      -F 'client_id=0123456798abcdef0123456798abcdef' \
      -F 'client_secret=0123456798abcdef0123456798abcdef' \
      -F 'id=10' \
      https://your.livewhale.com/live/unsubscribe/

If successful, you will receive a http status code of 200. Otherwise, you will receive a http status code error along with an error message as to the problem encountered.

#### List Subscriptions

To see your current subscriptions, submit a POST request to `/live/subscriptions` with your `client_id` and `client_secret`. (Https is recommended for security, but not required.) Again, unless your application requires active management of the subscriptions, you can use cURL to handle this:

    curl \
      -F 'client_id=0123456798abcdef0123456798abcdef' \
      -F 'client_secret=0123456798abcdef0123456798abcdef' \
      https://your.livewhale.com/live/subscriptions/

If successful, you will receive a JSON array of subscription objects or an empty array if no subscriptions exist. Otherwise, you will receive a http status code error along with an error message as to the problem encountered.

### Notifications

Once a subscription has been created, LiveWhale Push monitors all CRUD actions (creates, updates and deletes) to catch those that match your `object`, `group_id` and `tag` parameters. When a match is found, it will POST a JSON array of notification updates to your callback_url. Your web application should be prepared to receive the following (example shown):

    [
      {
        subscription_id: 9,
        object: 'news',
        object_id: 7043
        group_id: '',
        tag: 'audio',
        updated_at: '2011-06-14T14:53:27-07:00',
        is_new: false,
        is_deleted: false,
        changed: ['search_tags']
      },...
    ]

You may receive one or more updates at a time, so your application should be prepared for that possibility. Also, in the event that an item has a watched `tag` added or subtracted -- thus entering or exiting a watched status -- you will receive a notification in all cases so that your application can sort out an appropriate action.

In the event that LiveWhale Push cannot access your callback for any reason, it will send an email your the API client's email address with an error message along with any data that was being pushed, in whatever state it might be at the time of the error.

#### Content Retrieval 

If the content is live (i.e. not hidden or scheduled), you can retrieve the widget-available fields through the `/live/` request methods. For example, if you received an update for event id #6117, you could get that content as JSON or XML:

    http://your.livewhale.com/live/events/6114@JSON
    http://your.livewhale.com/live/events/6114@XML

The JSON version of the above request produces (from our LiveWhale instance):

    {
      id: '6114',
      title: 'Oregon Bus Project',
      date: '06/13/2011',
      date_time: null,
      date2: null,
      date2_time: null,
      repeats: null,
      repeats_until: null,
      summary: '<a href="http://busproject.org/">Summer Conference</a>',
      description: '<p>\n  <a href="http://busproject.org/">Summer Conference</a>\n</p>',
      location: 'JRHH 202 &amp; Grape Arbor',
      date_created: '2011-06-10 15:32:31',
      last_modified: '2011-06-14 09:39:33',
      has_registration: null,
      image: null,
      url: http://www.lclark.edu/live/events/6114-oregon-bus-project'
    }

The XML version produces the same result in XML with null elements dropped:

    <result>
      <id>6114</id>
      <title>Oregon Bus Project</title>
      <date>06/13/2011</date>
      <summary>&lt;a href="http://busproject.org/"&gt;Summer Conference&lt;/a&gt;</summary>
      <description>&lt;p&gt;
      &lt;a href="http://busproject.org/"&gt;Summer Conference&lt;/a&gt;
    &lt;/p&gt;</description>
      <location>JRHH 202 &amp; Grape Arbor</location>
      <date_created>2011-06-10 15:32:31</date_created>
      <last_modified>2011-06-14 09:39:33</last_modified>
      <url>http://www.lclark.edu/live/events/6114-oregon-bus-project</url>
    </result>

As noted above, if you need a greater level of access than this, you'll need an API or other method to retrieve data.

## Developers

To my knowledge, this is the first LiveWhale application module available as open source. If you have suggestions, contributions, errors, etc. please email me, or register an issue, or fork from the DEV branch and issue a pull request. If you add additional functionality, your pull request must have corresponding supporting documentation.
