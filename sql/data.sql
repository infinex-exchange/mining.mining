insert into mining_plans(
    name,
    months,
    unit_name,
    total_units,
    min_ord_units,
    unit_price,
    discount_perc_every,
    discount_max,
    enabled
) values(
    'BPX (solo) + Chia (pooled) double mining',
    72,
    'plot',
    1500,
    10,
    1.7,
    20,
    30,
    TRUE
);

insert into plan_assets(planid, assetid, priority) values
    (1, 'BPX', 1),
    (1, 'XCH', 2);
