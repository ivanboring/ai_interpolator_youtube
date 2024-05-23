# AI Interpolator Youtube
This is a module for the [AI Interpolator](https://www.drupal.org/project/ai_interpolator) module for Drupal.

Because youtube-dl gets constant take downs, I did not want to host this on drupal.org and get them into troubles, so here it is :)

## Install
Add the following into your composer.json on the repositories key:

```
"repositories": {
  "ai_interpolator_youtube": {
    "type": "vcs",
    "url": "https://github.com/ivanboring/ai_interpolator_youtube.git"
  }
},
```

then run:

`composer require "ivanboring/ai:interpolator:^1.0@alpha"`

## Requirements
For the video rule you need youtube-dl installed on your server.

For the audio rule you need youtube-dl and ffmpeg installed on your server.

## Usage
Just create a link field and then a file field where you choose on of the rules.

Video generates webm and audio mp3 for now.
