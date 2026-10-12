<?php

declare(strict_types=1);

use function Hyperf\Support\env;

/*
 * 通用 WebSocket 机制配置。
 *
 * 连接索引（Swoole\Table）是定长结构：容量与字符串列长度都必须在 fork 之前按峰值预留，
 * 写满之后 set() 只返回 false，溢出是静默丢数据。因此下面每一个容量项都不是「调优项」，
 * 而是「装不下就会丢连接」的硬边界。
 */
return [
    // 入口总开关。关闭时不注册入口路由、不创建连接索引表。
    'enabled' => (bool) env('WS_ENABLED', true),

    // 入口路径前缀：所有 #[WsGateway] 的 path 必须落在它之下（启动期校验）。
    'path' => (string) env('WS_PATH', '/ws'),

    // 单用户最大连接数（多端登录上限）。用户索引表按它预留字符串列长度，
    // 超过上限的连接会被拒绝注册，而不是静默丢掉索引。
    'max_connections_per_user' => (int) env('WS_MAX_CONN_PER_USER', 10),

    // 单连接最大房间数。
    'max_rooms_per_connection' => (int) env('WS_MAX_ROOMS', 20),

    // 单帧最大字节数，超过直接回错误信封，不解析。
    // 注意：它必须小于等于 server.settings.package_max_length，否则超限帧在协议层就被 Swoole
    // 断开，客户端拿到的是断连而不是错误信封（启动期检查会拦住这种配置）。
    'max_frame_bytes' => (int) env('WS_MAX_FRAME_BYTES', 65536),

    /*
     * 单连接消息限流（固定窗口）：window_seconds 秒内最多 max_messages 条消息。
     *
     * 超限只回错误信封、不断开连接。max_messages 设为 0 表示关闭。
     * 计数放在进程内存而不是共享内存表：网关强制单 worker（见 allow_multi_worker 说明），
     * 跨进程可见性没有意义，而每条消息多一次共享内存读写恰好会削弱限流本身要防的东西。
     */
    'rate_limit' => [
        'window_seconds' => (int) env('WS_RATE_LIMIT_WINDOW', 1),
        'max_messages' => (int) env('WS_RATE_LIMIT_MAX', 50),
    ],

    // 连接索引表容量，按峰值连接数预留（ws_conn 与 ws_user 共用）。
    'table_size' => (int) env('WS_TABLE_SIZE', 65536),

    // 单个房间的成员上限（ws_room 的字符串列长度）。
    // 房间成员列表是「一个房间一行、行内放连接 id 列表」的定长结构，内存占用是
    // 房间数 × 成员上限 的乘积，所以不能像连接表那样按峰值连接数预留，必须单独配置。
    'room_member_capacity' => (int) env('WS_ROOM_MEMBER_CAPACITY', 128),

    // 房间索引表容量（能同时存在的房间数上限）。内存 ≈ 本值 × (room_member_capacity × 33) 字节。
    'room_table_size' => (int) env('WS_ROOM_TABLE_SIZE', 2048),

    /*
     * 空闲连接回收：超过本秒数没有收到任何消息的连接会被主动断开。
     *
     * 为什么必须有：网络中断（拔网线、进程被 kill、NAT 超时）不会触发 onClose，连接行会一直
     * 挂在定长表里 —— 写满之后新连接注册直接失败、被拒，且不会自愈。协程服务器下 Swoole 的
     * heartbeat 配置不生效（那是 Swoole\Server 的 settings），所以回收只能由应用层做。
     *
     * 客户端需要在本时限内定期发心跳（内置事件 sys.ping）。设为 0 表示关闭，
     * 此时必须另有回收手段（如 Swoole 的 heartbeat_idle_time），否则启动期检查会直接失败。
     */
    'idle_timeout' => (int) env('WS_IDLE_TIMEOUT', 180),

    // 空闲扫描间隔（秒）。每次扫描是 O(连接表容量) 的共享内存遍历，不必太频繁。
    'idle_check_interval' => (int) env('WS_IDLE_CHECK_INTERVAL', 30),

    /*
     * 协程服务器下的逃生舱：允许 server.settings.worker_num > 1 时启动。
     *
     * Hyperf 3.1 起 server.type 默认是协程服务器，而它的 Sender 只能在当前 worker 内投递
     * （fd 属于别的 worker 时直接返回 false，不转发），多 worker 下大部分推送会静默丢失。
     * 只有「只收不发」的拓扑才应该打开这个开关；默认关闭，让启动期检查把问题暴露出来。
     */
    'allow_multi_worker' => (bool) env('WS_ALLOW_MULTI_WORKER', false),

    /*
     * 本地人工测试入口开关（例如应用自建的 /ws/test 探针）。
     *
     * 该入口不做身份校验，所以默认关闭，本地人工测试时显式打开：WS_TEST_ENTRY_ENABLED=true。
     *
     * 曾经的默认值是「跟随 APP_ENV，非 prod 打开」：漏配 APP_ENV 时 Hyperf 按 dev 处理，
     * 于是一次「忘了设环境变量」的部署就把无鉴权入口对外开了。安全默认值不能依赖别的变量
     * 是否被正确设置。
     *
     * 探针入口由应用自行提供并排除在版本管理外（定位是「本地人工测试工具」），
     * 所以仓库里看不到读这个键的中间件 —— 本地把探针放回去即可生效。
     */
    'test_entry_enabled' => (bool) env('WS_TEST_ENTRY_ENABLED', false),

    /*
     * 不进对外契约的入口类与事件处理器类，支持 * 通配（与 api_doc.excluded 同一套匹配语义）。
     *
     * 只影响对外契约文档（Apifox 文档，api-doc:generate-apifox）。
     * **不影响路由注册**：列在这里的入口照样可以连接，只是不出现在对外文档里。
     *
     * 应用自己的内部 / 调试入口不进版本管理；将来新增这类入口，在这里补一条即可。
     */
    'excluded' => [
        // 'App\Controller\Internal\*',
    ],
];
