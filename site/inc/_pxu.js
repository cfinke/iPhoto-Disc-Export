(function() {
  window.PXU = (function() {
    var $, NAVIGATION_FADE_DURATION;

    $ = jQuery;

    function PXU() {
      this.$header = $("header");
      this.$navigation = $(".primary-navigation");
      this.$navToggle = $(".navigation-toggle");
      this.bindEvents();
      this.setupNavigation();
      this.setupPortfolio();
      this.setupSharing();
      //this.setupTitleText();
    }

    PXU.prototype.onResize = function() {
      var articleWidth, maxArticleWidth, portfolioRightOverflow;
      articleWidth = $("article").width();
      maxArticleWidth = 700;
      portfolioRightOverflow = 5;
      $(".full-image").css({
        width: articleWidth + portfolioRightOverflow,
        marginLeft: -(articleWidth - maxArticleWidth) / 2
      });
      this.fixedHeader();
      this._closeNavigation(0);
      return this.resizeVideos();
    };

    PXU.prototype.onToggleNavButtonClick = function() {
      if (this.$navToggle.hasClass('active')) {
        return this._closeNavigation();
      } else {
        return this._openNavigation();
      }
    };

    NAVIGATION_FADE_DURATION = 125;

    PXU.prototype._closeNavigation = function(animationDuration) {
      if (animationDuration == null) {
        animationDuration = NAVIGATION_FADE_DURATION;
      }
      this.$navToggle.toggleClass("active", false);
      return this.$navigation.animate({
        opacity: 0
      }, {
        duration: animationDuration,
        complete: (function(_this) {
          return function() {
            _this.$navigation.toggleClass("active", false);
            return $("#page").css({
              height: "",
              overflow: ""
            });
          };
        })(this)
      });
    };

    PXU.prototype._openNavigation = function() {
      var headerHeight;
      this.$navToggle.toggleClass("active", true);
      this.$navigation.toggleClass("active", true);
      headerHeight = this.$header.outerHeight();
      this.$navigation.css({
        opacity: 0.5,
        top: headerHeight,
        "min-height": $(window).height() - headerHeight
      }).animate({
        opacity: 1
      }, NAVIGATION_FADE_DURATION);
      return $("#page").css({
        height: headerHeight + this.$navigation.outerHeight(),
        overflow: "hidden"
      });
    };

    PXU.prototype.bindEvents = function() {
      var galleryContainer;
      $(window).resize((function(_this) {
        return function() {
          return _this.onResize();
        };
      })(this)).trigger("resize");
      $(document.body).on("post-load", (function(_this) {
        return function(e) {
          _this.setupSharing();
          return $(".navigation").remove();
        };
      })(this));
      $("#main").on('click', '.share-button', function() {
        $(this).siblings(".like-button.active, .likes-wrap.active").removeClass('active');
        $(this).toggleClass('active');
        return $(this).siblings(".share-wrap").toggleClass('active');
      });
      $("#main").on('click', '.like-button', function() {
        $(this).siblings(".share-button.active, .share-wrap.active").removeClass('active');
        $(this).toggleClass('active');
        return $(this).siblings(".likes-wrap").toggleClass('active');
      });
      $(".permalink-navigation .next").each((function(_this) {
        return function(i, el) {
          return $(el).click(function(event) {
            var currentArticle, nextArticle;
            currentArticle = $(event.target).parents("article");
            nextArticle = currentArticle.next();
            if (nextArticle.length) {
              nextArticle.fadeIn(275);
              currentArticle.hide();
              return _this.resizeVideos(nextArticle);
            }
          });
        };
      })(this));
      $(".permalink-navigation .prev").each((function(_this) {
        return function(i, el) {
          return $(el).click(function(event) {
            var currentArticle, prevArticle;
            currentArticle = $(event.target).parents("article");
            prevArticle = currentArticle.prev();
            if (prevArticle.length) {
              prevArticle.fadeIn(275);
              currentArticle.hide();
              return _this.resizeVideos(prevArticle);
            }
          });
        };
      })(this));
      this.$navToggle.click((function(_this) {
        return function() {
          return _this.onToggleNavButtonClick();
        };
      })(this));
      galleryContainer = $("#gallery");
      if (!galleryContainer.hasClass('direct-link')) {
        return galleryContainer.find("li .dimmer").click((function(_this) {
          return function(event) {
            var post_class;
            event.preventDefault();
            post_class = $(event.target).parents("li").attr("class").match(/post\-.+?\b/)[0];
            $("#gallery").addClass("active");
            $("#permalinks article").hide();
            $("html, body").scrollTop(0);
            if (!$('html').hasClass('touch')) {
              $("#permalinks ." + post_class).css({
                display: "inline-block",
                'margin-top': -50,
                opacity: 0.5
              }).animate({
                opacity: 1,
                marginTop: 0
              }, 275);
            } else {
              $("#permalinks ." + post_class).css({
                display: "inline-block"
              });
            }
            return _this.resizeVideos($("#permalinks ." + post_class));
          };
        })(this));
      }
    };

    PXU.prototype.setupPortfolio = function() {
      var articleWidth, maxArticleWidth, portfolioRightOverflow;
      articleWidth = $("article").width();
      maxArticleWidth = 700;
      portfolioRightOverflow = 5;
      $(".full-image").css({
        width: articleWidth + portfolioRightOverflow,
        marginLeft: -(articleWidth - maxArticleWidth) / 2
      });
      $('article.portfolio-post').each((function(_this) {
        return function(i, el) {
          var imageData, imageURL, postContent, postHeader, video;
          postHeader = $(el).find('.post-header');
          postContent = $(el).find('.post-content');
          if ($(el).hasClass('use-video')) {
            video = postContent.find('iframe').first() || postContent.find('embed').first() || postContent.find('object').first();
            if (video) {
              video.remove();
              postHeader.find('.post-title').after($('<div class="featured-video" />').html(video));
              return _this.resizeVideos($(el));
            }
          } else {
            imageData = postHeader.find('a[data-featured-image]');
            imageURL = imageData.data("featured-image");
            if (imageURL) {
              return imageData.after("<img class='featured-image' src=\"" + imageURL + "\"/>");
            }
          }
        };
      })(this));
      return $("#gallery").before($("#permalinks"));
    };

    PXU.prototype.resizeVideos = function(content) {
      var videos;
      videos = content ? content.find('iframe, embed, object') : $('article').find('iframe, embed, object');
      return videos.each(function() {
        var $video, aspect, height, maxWidth, width;
        $video = $(this).css({
          width: "",
          height: ""
        });
        width = $video.attr("width") || $video.width();
        height = $video.attr("height") || $video.height();
        maxWidth = $video.parent().innerWidth();
        aspect = width / height;
        return $video.css({
          width: maxWidth,
          height: maxWidth / aspect
        });
      });
    };

    PXU.prototype.setupSharing = function() {
      return $('article:not(.processed)').each(function() {
        var article;
        article = $(this);
        if ((article.find(".share-wrap .sharedaddy").length)) {
          article.find(".share-button").css("display", "inline-block");
        }
        if ((article.find(".likes-wrap div").length)) {
          article.find(".like-button").css("display", "inline-block");
        }
        return article.addClass('processed');
      });
    };

    PXU.prototype.fixedHeader = function() {
      var contactBox, contactEl, dimmerHeight, navigationBox, overlap, windowHeight;
      windowHeight = $(window).height();
      dimmerHeight = $('.site-header-dimmer').outerHeight();
      if ($('.site-header').hasClass('no-sticky')) {
        if (dimmerHeight < windowHeight) {
          return $('.site-header').removeClass('no-sticky');
        }
      } else {
        navigationBox = this.$navigation[0].getBoundingClientRect();
        contactEl = $('.contact');
        if (contactEl.length) {
          contactBox = contactEl[0].getBoundingClientRect();
          overlap = !(navigationBox.right < contactBox.left || navigationBox.left > contactBox.right || navigationBox.bottom < contactBox.top || navigationBox.top > contactBox.bottom);
          if (overlap) {
            return $('.site-header').addClass('no-sticky');
          }
        }
      }
    };

    PXU.prototype.setupNavigation = function() {
      this.$navigation.find("li").each(function() {
        var listItem;
        listItem = $(this);
        if (listItem.children().length === 2) {
          return listItem.children("a").addClass("menu-label");
        }
      });
      $(".primary-navigation ul ul").each(function() {
        return $(this).wrap("<div class=\"sub-menu-wrap\" />").prepend("<div class=\"sub-menu-border\" />");
      });
      return this.fixedHeader();
    };

    PXU.prototype.setupTitleText = function() {
      var titleText;
      titleText = this.$header.find('.site-title');
      return $(window).resize(function() {
        return titleText.fitText(0.65, {
          minFontSize: 28,
          maxFontSize: 42
        });
      }).trigger('resize');
    };

    return PXU;

  })();

}).call(this);
