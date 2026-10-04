/* ============================================================
 * assets/js/modules/scroll.js — 通用「滚动到底自动加载」模块（PvInfiniteScroll）
 * ============================================================
 * 统一患者列表、操作日志等一切列表的向下滚动分页加载，避免各页重复实现哨兵逻辑。
 *
 * 用法：
 *   var loader = PvInfiniteScroll.create({
 *       container : 滚动容器元素（IntersectionObserver 的 root）,
 *       list      : 追加哨兵的元素（默认同 container）,
 *       offset    : 初始偏移量（已加载条数）,
 *       hasMore   : 是否还有更多,
 *       pageSize  : 每次加载条数（默认 30）,
 *       moreText  : 加载中的提示文案,
 *       endText   : 全部加载完成的提示文案（空串 = 不显示）,
 *       load      : function(offset, pageSize) → Promise<{list, has_more, total}>,
 *       append    : function(list, res)  // 将新数据渲染进列表,
 *       onState   : function(loader)     // 每次加载后回调（可选）
 *   });
 *   loader.reset({ offset, hasMore });   // 列表被重置（重新检索）后调用
 *   loader.reattach();                    // 调用方重建了列表 DOM 后重新挂哨兵
 *   loader.destroy();
 *
 * 另提供高层封装 create/paged()：站内列表接口统一返回 {code,msg,data:{list,has_more,total}}，
 * paged() 内聚「取数归一化 + 失败提示 + 状态回调」，进一步消除各列表页的重复样板：
 *   var loader = PvInfiniteScroll.paged({
 *       container: scrollEl, list: scrollEl, pageSize: 30, offset: 0, hasMore: true,
 *       request: function (offset, size) { return PvUI.get(url); },   // 标准 {code,msg,data}
 *       append : function (list) { ... },                              // 渲染新增数据
 *       onState: function (s) { ... },                                 // {offset, hasMore, total}
 *       onError: function (msg) { ... }
 *   });
 * ============================================================ */
(function (global) {
    'use strict';

    function Infinite(opts) {
        opts = opts || {};
        this.opts = opts;
        this.offset = Math.max(0, parseInt(opts.offset, 10) || 0);
        this.hasMore = opts.hasMore !== false;
        this.total = (typeof opts.total === 'number') ? opts.total : null;
        this.loading = false;
        this.destroyed = false;
        this.sentinel = null;
        this.io = null;
        this._place();
    }

    Infinite.prototype._container = function () { return this.opts.container || null; };
    Infinite.prototype._list = function () { return this.opts.list || this.opts.container || null; };

    /** 在列表末尾放置（或复位）哨兵 */
    Infinite.prototype._place = function () {
        if (this.destroyed) return;
        var list = this._list();
        if (!list) return;
        if (!this.sentinel) {
            this.sentinel = document.createElement('div');
            this.sentinel.className = this.opts.sentinelClass || 'pv-scroll-more';
        }
        this.sentinel.textContent = this.hasMore ? (this.opts.moreText || '上拉加载更多…') : (this.opts.endText || '');
        list.appendChild(this.sentinel);
        this._observe();
    };

    Infinite.prototype._observe = function () {
        var self = this;
        if (!global.IntersectionObserver || !this.sentinel || !this.hasMore) return;
        if (this.io) { this.io.disconnect(); this.io = null; }
        this.io = new global.IntersectionObserver(function (entries) {
            if (entries[0] && entries[0].isIntersecting) self.loadMore();
        }, { root: this._container(), rootMargin: this.opts.rootMargin || '360px' });
        this.io.observe(this.sentinel);
    };

    /** 主动请求下一页（也可由滚动自动触发） */
    Infinite.prototype.loadMore = function () {
        var self = this;
        if (this.loading || !this.hasMore || this.destroyed) return Promise.resolve();
        if (typeof this.opts.load !== 'function') return Promise.resolve();
        this.loading = true;
        if (this.sentinel) this.sentinel.textContent = this.opts.loadingText || '加载中…';
        return Promise.resolve(this.opts.load(this.offset, this.opts.pageSize || 30))
            .then(function (res) {
                if (self.destroyed) return;
                res = res || {};
                var list = res.list || [];
                if (typeof self.opts.append === 'function') self.opts.append(list, res);
                self.offset += list.length;
                self.hasMore = !!res.has_more;
                if (typeof res.total === 'number') self.total = res.total;
                self.loading = false;
                if (self.io) { self.io.disconnect(); self.io = null; }
                self._place();                       // 追加完成后重新放回哨兵（兼容 append 重建列表的情况）
                if (typeof self.opts.onState === 'function') self.opts.onState(self);
            })
            .catch(function () {
                self.loading = false;
                if (self.sentinel) self.sentinel.textContent = self.opts.errorText || '加载失败，滚动重试…';
                self._observe();
            });
    };

    /** 列表被重建后重新挂载哨兵（不清空 offset / hasMore） */
    Infinite.prototype.reattach = function () {
        if (this.destroyed) return;
        this._place();
    };

    /** 重置加载进度（重新检索时调用）；可选立即触发一次加载 */
    Infinite.prototype.reset = function (o) {
        o = o || {};
        if (this.sentinel && this.sentinel.parentNode) this.sentinel.parentNode.removeChild(this.sentinel);
        if (this.io) { this.io.disconnect(); this.io = null; }
        this.offset = Math.max(0, parseInt(o.offset, 10) || 0);
        this.hasMore = o.hasMore !== false;
        this.loading = false;
        this._place();
        if (o.immediate) return this.loadMore();
        return Promise.resolve();
    };

    Infinite.prototype.destroy = function () {
        this.destroyed = true;
        if (this.io) { this.io.disconnect(); this.io = null; }
        if (this.sentinel && this.sentinel.parentNode) this.sentinel.parentNode.removeChild(this.sentinel);
    };

    /**
     * 高层便捷封装：统一「取数 → 归一化 → 追加 → 状态回调」，适配站内标准接口响应。
     * @param {object} o
     *   container, list, pageSize, offset, hasMore, sentinelClass, moreText, endText,
     *   loadingText, errorText, rootMargin
     *   request(offset, pageSize) → Promise<{code,msg,data:{list,has_more,total}}>
     *   append(list, data)、onState({offset,hasMore,total})、onError(msg)
     * @return {Infinite}
     */
    function paged(o) {
        o = o || {};
        var size = o.pageSize || 30;
        return new Infinite({
            container: o.container, list: o.list, pageSize: size,
            offset: o.offset, hasMore: o.hasMore, total: o.total,
            sentinelClass: o.sentinelClass, moreText: o.moreText, endText: o.endText,
            loadingText: o.loadingText, errorText: o.errorText, rootMargin: o.rootMargin,
            load: function (offset) {
                return Promise.resolve(o.request(offset, size)).then(function (j) {
                    if (!j || j.code !== 200) {
                        var msg = (j && j.msg) || o.errorMsg || '加载失败';
                        if (typeof o.onError === 'function') o.onError(msg);
                        throw new Error(msg);
                    }
                    var d = j.data || {};
                    return { list: d.list || [], has_more: !!d.has_more, total: d.total };
                });
            },
            append: function (list, res) { if (typeof o.append === 'function') o.append(list, res); },
            onState: function (ld) {
                if (typeof o.onState === 'function') o.onState({ offset: ld.offset, hasMore: ld.hasMore, total: ld.total });
            }
        });
    }

    global.PvInfiniteScroll = {
        create: function (opts) { return new Infinite(opts); },
        paged: paged
    };
})(window);
