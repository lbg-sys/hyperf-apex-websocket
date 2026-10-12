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
    'max_frame_bytes' => (int) env('WS_MAX_FRAME_BYTES', 65536),

    // 连接索引表容量，按峰值连接数预留（ws_conn 与 ws_user 共用）。
    'table_size' => (int) env('WS_TABLE_SIZE', 65536),

    // 单个房间的成员上限（ws_room 的字符串列长度）。
    // 房间成员列表是「一个房间一行、行内放连接 id 列表」的定长结构，内存占用是
    // 房间数 × 成员上限 的乘积，所以不能像连接表那样按峰值连接数预留，必须单独配置。
    'room_member_capacity' => (int) env('WS_ROOM_MEMBER_CAPACITY', 128),

    // 房间索引表容量（能同时存在的房间数上限）。内存 ≈ 本值 × (room_member_capacity × 33) 字节。
    'room_table_size' => (int) env('WS_ROOM_TABLE_SIZE', 2048),

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
