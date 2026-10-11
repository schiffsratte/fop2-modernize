/**
 * Timeago is a jQuery plugin that makes it easy to support automatically
 * updating fuzzy timestamps (e.g. "4 minutes ago" or "about 1 day ago").
 *
 * @name timeago
 * @version 1.4.1
 * @requires jQuery v1.2.3+
 * @author Ryan McGeary
 * @license MIT License - http://www.opensource.org/licenses/mit-license.php
 *
 * For usage and examples, visit:
 * http://timeago.yarp.com/
 *
 * Copyright (c) 2008-2015, Ryan McGeary (ryan -[at]- mcgeary [*dot*] org)
 */

(function (factory) {
  if (typeof define === 'function' && define.amd) {
    // AMD. Register as an anonymous module.
    define(['jquery'], factory);
  } else {
    // Browser globals
    factory(jQuery);
  }
}(function ($) {
  $.timeago = function(timestamp) {
    if (timestamp instanceof Date) {
      return inWords(timestamp);
    } else if (typeof timestamp === "string") {
      return inWords($.timeago.parse(timestamp));
    } else if (typeof timestamp === "number") {
      return inWords(new Date(timestamp));
    } else {
      return inWords($.timeago.datetime(timestamp));
    }
  };
  var $t = $.timeago;

  $.extend($.timeago, {
    settings: {
      refreshMillis: 0,
      allowPast: true,
      allowFuture: false,
      localeTitle: false,
      timeZone: "Europe/Paris",
      locale: null,
      cutoff: 0,
      strings: {
        prefixAgo: null,
        prefixFromNow: null,
        suffixAgo: "ago",
        suffixFromNow: "from now",
        inPast: 'any moment now',
        seconds: "less than a minute",
        minute: "about a minute",
        minutes: "%d minutes",
        hour: "about an hour",
        hours: "about %d hours",
        day: "a day",
        days: "%d days",
        month: "about a month",
        months: "%d months",
        year: "about a year",
        years: "%d years",
        wordSeparator: " ",
        numbers: []
      }
    },

    formatDateTime: function(date) {
      var locale = normalizeLocale(this.settings.locale || currentLanguage());
      var options = {
        timeZone: this.settings.timeZone,
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
        hourCycle: "h23",
        hour12: false
      };

      if (window.Intl && Intl.DateTimeFormat) {
        try {
          return formatParts(new Intl.DateTimeFormat(locale, options).formatToParts(date));
        } catch (e) {
          try {
            return formatParts(new Intl.DateTimeFormat("fr-FR", options).formatToParts(date));
          } catch (fallbackError) {
            return fallbackParisDateTime(date);
          }
        }
      }

      return fallbackParisDateTime(date);
    },

    inWords: function(distanceMillis) {
      if(!this.settings.allowPast && ! this.settings.allowFuture) {
          throw 'timeago allowPast and allowFuture settings can not both be set to false.';
      }

      var $l = this.settings.strings;
      var prefix = $l.prefixAgo;
      var suffix = $l.suffixAgo;
      if (this.settings.allowFuture) {
        if (distanceMillis < 0) {
          prefix = $l.prefixFromNow;
          suffix = $l.suffixFromNow;
        }
      }

      if(!this.settings.allowPast && distanceMillis >= 0) {
        return this.settings.strings.inPast;
      }

      var seconds = Math.abs(distanceMillis) / 1000;
      var minutes = seconds / 60;
      var hours = minutes / 60;
      var days = hours / 24;
      var years = days / 365;

      function substitute(stringOrFunction, number) {
        var string = $.isFunction(stringOrFunction) ? stringOrFunction(number, distanceMillis) : stringOrFunction;
        var value = ($l.numbers && $l.numbers[number]) || number;
        return string.replace(/%d/i, value);
      }

      var words = seconds < 45 && substitute($l.seconds, Math.round(seconds)) ||
        seconds < 90 && substitute($l.minute, 1) ||
        minutes < 45 && substitute($l.minutes, Math.round(minutes)) ||
        minutes < 90 && substitute($l.hour, 1) ||
        hours < 24 && substitute($l.hours, Math.round(hours)) ||
        hours < 42 && substitute($l.day, 1) ||
        days < 30 && substitute($l.days, Math.round(days)) ||
        days < 45 && substitute($l.month, 1) ||
        days < 365 && substitute($l.months, Math.round(days / 30)) ||
        years < 1.5 && substitute($l.year, 1) ||
        substitute($l.years, Math.round(years));

      var separator = $l.wordSeparator || "";
      if ($l.wordSeparator === undefined) { separator = " "; }
      return $.trim([prefix, words, suffix].join(separator));
    },

    parse: function(iso8601) {
      var s = $.trim(iso8601);
      s = s.replace(/\.\d+/,""); // remove milliseconds
      s = s.replace(/-/,"/").replace(/-/,"/");
      s = s.replace(/T/," ").replace(/Z/," UTC");
      s = s.replace(/([\+\-]\d\d)\:?(\d\d)/," $1$2"); // -04:00 -> -0400
      s = s.replace(/([\+\-]\d\d)$/," $100"); // +09 -> +0900
      return new Date(s);
    },
    datetime: function(elem) {
      var element = $(elem);
      var iso8601 = $t.isTime(elem) ? element.attr("datetime") : element.data("timeago-source") || element.attr("title");
      return $t.parse(iso8601);
    },
    isTime: function(elem) {
      // jQuery's `is()` doesn't play well with HTML5 in IE
      return $(elem).get(0).tagName.toLowerCase() === "time"; // $(elem).is("time");
    }
  });

  // functions that can be called via $(el).timeago('action')
  // init is default when no action is given
  // functions are called with context of a single element
  var functions = {
    init: function(){
      var refresh_el = $.proxy(refresh, this);
      refresh_el();
      var $s = $t.settings;
      if ($s.refreshMillis > 0) {
        this._timeagoInterval = setInterval(refresh_el, $s.refreshMillis);
      }
    },
    update: function(time){
      var parsedTime = $t.parse(time);
      $(this).data('timeago', { datetime: parsedTime });
      setTitle($(this), parsedTime);
      refresh.apply(this);
    },
    updateFromDOM: function(){
      $(this).data('timeago', { datetime: $t.datetime(this) });
      refresh.apply(this);
    },
    dispose: function () {
      if (this._timeagoInterval) {
        window.clearInterval(this._timeagoInterval);
        this._timeagoInterval = null;
      }
    }
  };

  $.fn.timeago = function(action, options) {
    var fn = action ? functions[action] : functions.init;
    if(!fn){
      throw new Error("Unknown function name '"+ action +"' for timeago");
    }
    // each over objects here and call the requested function
    this.each(function(){
      fn.call(this, options);
    });
    return this;
  };

  function refresh() {
    //check if it's still visible
    if(!$.contains(document.documentElement,this)){
      //stop if it has been removed
      $(this).timeago("dispose");
      return this;
    }

    var data = prepareData(this);

    if (!isNaN(data.datetime)) {
      $(this).text($t.formatDateTime(data.datetime));
    }
    return this;
  }

  function prepareData(element) {
    element = $(element);
    if (!element.data("timeago")) {
      if (!$t.isTime(element) && !element.data("timeago-source")) {
        element.data("timeago-source", element.attr("title"));
      }
      element.data("timeago", { datetime: $t.datetime(element) });
      setTitle(element, element.data('timeago').datetime);
    }
    return element.data("timeago");
  }

  function inWords(date) {
    return $t.formatDateTime(date);
  }

  function distance(date) {
    return (new Date().getTime() - date.getTime());
  }

  function setTitle(element, date) {
    var formatted = $t.formatDateTime(date);
    element.attr("title", formatted);
    element.attr("data-original-title", formatted);
  }

  function currentLanguage() {
    if (window.language) {
      return window.language;
    }

    var lang = $("script[src*='lang_']").last().attr("src");
    if (lang) {
      lang = lang.replace(/^.*lang_/, "").replace(/\.js(?:\?.*)?$/, "");
      if (lang) {
        return lang;
      }
    }

    return document.documentElement.lang || navigator.language || navigator.userLanguage || "fr-FR";
  }

  function normalizeLocale(locale) {
    locale = (locale || "fr-FR").replace("_", "-");
    if (locale === "cr") {
      return "ca";
    }
    return locale;
  }

  function fallbackParisDateTime(date) {
    var paris = new Date(date.getTime() + parisOffsetMinutes(date) * 60000);
    return pad(paris.getUTCDate()) + "/" +
      pad(paris.getUTCMonth() + 1) + "/" +
      paris.getUTCFullYear() + " " +
      pad(paris.getUTCHours()) + ":" +
      pad(paris.getUTCMinutes()) + ":" +
      pad(paris.getUTCSeconds());
  }

  function formatParts(parts) {
    var date = {};

    $.each(parts, function(index, part) {
      if (part.type !== "literal") {
        date[part.type] = part.value;
      }
    });

    if (date.hour === "24") {
      date.hour = "00";
    }

    return date.day + "/" + date.month + "/" + date.year + " " +
      date.hour + ":" + date.minute + ":" + date.second;
  }

  function parisOffsetMinutes(date) {
    var year = date.getUTCFullYear();
    var dstStart = lastSundayUtc(year, 2);
    var dstEnd = lastSundayUtc(year, 9);
    return date >= dstStart && date < dstEnd ? 120 : 60;
  }

  function lastSundayUtc(year, month) {
    var date = new Date(Date.UTC(year, month + 1, 0, 1, 0, 0));
    date.setUTCDate(date.getUTCDate() - date.getUTCDay());
    return date;
  }

  function pad(number) {
    return number < 10 ? "0" + number : "" + number;
  }

  // fix for IE6 suckage
  document.createElement("abbr");
  document.createElement("time");
}));
